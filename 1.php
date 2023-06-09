<?php

namespace App\Services\Calculator\Discount;

use App\Models\Discount\Discount;
use App\Models\Discount\DiscountCondition;
use App\Models\Discount\DiscountCondition as DiscountConditionModel;
use App\Services\Calculator\AbstractCalculator;
use App\Services\Calculator\Discount\Applier\BasketApplier;
use App\Services\Calculator\Discount\Applier\DeliveryApplier;
use App\Services\Calculator\Discount\Applier\OfferApplier;
use App\Services\Calculator\Discount\Checker\DifferentProductsCountChecker;
use App\Services\Calculator\Discount\Checker\DiscountConditionChecker;
use App\Services\Calculator\InputCalculator;
use App\Services\Calculator\OutputCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class DiscountCalculator
 * @package App\Services\Calculator\Discount
 */
class DiscountCalculator extends AbstractCalculator
{
    /**
     * Скидки, которые активированы с помощью промокода
     * @var Collection
     */
    protected $appliedDiscounts;

    /**
     * Список скидок, которые можно применить (отдельно друг от друга)
     * @var Collection
     */
    protected $possibleDiscounts;

    /**
     * Офферы со скидками в формате:
     * [basket_item_id => [['id' => discount_id, 'value' => value, 'value_type' => value_type], ...]]
     * @var Collection
     */
    protected $basketItemsByDiscounts;

    /**
     * Список активных скидок
     * @var Collection
     */
    protected $discounts;

    /**
     * DiscountCalculator constructor.
     */
    public function __construct(InputCalculator $inputCalculator, OutputCalculator $outputCalculator)
    {
        parent::__construct($inputCalculator, $outputCalculator);

        $this->discounts = collect();
        $this->appliedDiscounts = collect();
        $this->possibleDiscounts = collect();
        $this->basketItemsByDiscounts = collect();
    }

    public function calculate()
    {
        if (!$this->needCalculate()) {
            return;
        }

        $this->fetchDiscounts();

        if (!empty($this->input->deliveries['items'])) {
            if (!$this->input->freeDelivery) {
                $this->filter()->sort()->apply()->rollback();
            }

            $this->input->deliveries['current'] = $this->input->deliveries['items']->filter(function ($item) {
                return $item['selected'];
            })->first();
        }

        /** Считаются окончательные скидки + бонусы */
        $this->filter()->sort()->apply();

        $this->getDiscountOutput();
    }

    protected function needCalculate(): bool
    {
        return $this->input->payment['isNeedCalculate'];
    }

    protected function fetchDiscounts(): void
    {
        $discountFetcher = new DiscountFetcher($this->input);
        $this->discounts = $discountFetcher->getDiscounts();
    }

    private function getDiscountOutput(): void
    {
        $discountOutput = new DiscountOutput($this->input, $this->discounts, $this->basketItemsByDiscounts, $this->appliedDiscounts);
        $this->input->basketItems = $discountOutput->getBasketItems();
        $this->basketItemsByDiscounts = $discountOutput->getModifiedBasketItemsByDiscounts();
        $this->appliedDiscounts = $discountOutput->getModifiedAppliedDiscounts();
        $this->output->appliedDiscounts = $discountOutput->getOutputFormat();
    }

    /**
     * Полностью откатывает все примененные скидки
     */
    public function forceRollback(): self
    {
        return $this->rollback();
    }

    /**
     * Применяет скидки
     */
    protected function apply(): self
    {
        /** @var Discount $discount */
        foreach ($this->possibleDiscounts as $discount) {
            $this->applyDiscount($discount);
        }

        return $this->processFreeProducts();
    }

    /**
     * Делает товар бесплатным, если была применена только одна скидка на 100% или равная сумме товара в рублях
     */
    protected function processFreeProducts(): self
    {
        foreach ($this->input->basketItems as $basketItem) {
            if ($this->basketItemsByDiscounts->has($basketItem['id'])) {
                [$freeProductDiscounts, $otherDiscounts] = $this->basketItemsByDiscounts->get($basketItem['id'])
                    ->partition(fn($discount) =>
                        $discount['value_type'] == Discount::DISCOUNT_VALUE_TYPE_PERCENT && $discount['value'] == 100 ||
                        $discount['value_type'] == Discount::DISCOUNT_VALUE_TYPE_RUB && $discount['value'] == $basketItem['cost']
                    );

                // Если применена 100% скидка на товар и нет других скидок, то делаем этот товар бесплатным
                if ($freeProductDiscounts->count() > 0 && $otherDiscounts->count() == 0) {
                    $basketItem['price'] = 0;
                }
            }
        }

        return $this;
    }

    protected function applyDiscount(Discount $discount): float|false
    {
        if (!$this->isCompatibleDiscount($discount)) {
            return false;
        }

        $change = false;
        switch ($discount->type) {
            case Discount::DISCOUNT_TYPE_OFFER:
                # Скидка на определенные офферы
                $offerIds = $discount->offers->pluck('offer_id');
                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
            case Discount::DISCOUNT_TYPE_ANY_OFFER:
                # Скидка на все товары
                # За исключением офферов
                $exceptOfferIds = $this->getExceptOffersForDiscount($discount);
                $offerIds = $this->input->basketItems
                    ->where('product_id', '!=', null)
                    ->whereNotIn('offer_id', $exceptOfferIds)
                    ->pluck('offer_id');

                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
            case Discount::DISCOUNT_TYPE_BUNDLE_OFFER:
            case Discount::DISCOUNT_TYPE_BUNDLE_MASTERCLASS:
            case Discount::DISCOUNT_TYPE_ANY_BUNDLE:
                # Скидка на бандлы
                # Определяем id офферов по бандлам
                $bundleItems = $discount->bundleItems;
                if (in_array($discount->type, [Discount::DISCOUNT_TYPE_BUNDLE_OFFER, Discount::DISCOUNT_TYPE_BUNDLE_MASTERCLASS])) {
                    $offerIds = $bundleItems->pluck('item_id');
                } else {
                    $exceptBundleIds = $discount->bundles->pluck('bundle_id');
                    $offerIds = $this->input->basketItems->filter(function ($basketItem) use ($exceptBundleIds) {
                        return $basketItem['bundle_id'] > 0 && !$exceptBundleIds->contains($basketItem['bundle_id']);
                    })
                        ->pluck('offer_id');
                }

                if (in_array($discount->type, [Discount::DISCOUNT_TYPE_BUNDLE_OFFER, Discount::DISCOUNT_TYPE_ANY_BUNDLE])) {
                    $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                    $offerApplier->setOfferIds($offerIds);
                    $change = $offerApplier->apply($discount);
                    $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                    $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                }

                // todo Рассчет скидки для мастерклассов

                break;
            case Discount::DISCOUNT_TYPE_BRAND:
            case Discount::DISCOUNT_TYPE_ANY_BRAND:
                # Скидка на бренды
                /** @var Collection $brandIds */
                $brandIds = $discount->type == Discount::DISCOUNT_TYPE_BRAND
                    ? $discount->brands->pluck('brand_id')
                    : $this->input->brands->keys();

                # За исключением брендов
                $exceptBrandIds = $this->getExceptBrandsForDiscount($discount);
                $brandIds = $brandIds->diff($exceptBrandIds);
                # За исключением офферов
                $exceptOfferIds = $this->getExceptOffersForDiscount($discount);
                # Отбираем нужные офферы
                $offerIds = $this->filterForBrand($brandIds, $exceptOfferIds, $discount->merchant_id);
                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
            case Discount::DISCOUNT_TYPE_CATEGORY:
            case Discount::DISCOUNT_TYPE_ANY_CATEGORY:
                # Скидка на категории
                /** @var Collection $categoryIds */
                $categoryIds = $discount->type == Discount::DISCOUNT_TYPE_CATEGORY
                    ? $discount->categories->pluck('category_id')
                    : $this->input->categories->keys();

                # За исключением категорий
                $exceptCategoryIds = $this->getExceptCategoriesForDiscount($discount);
                $categoryIds = $categoryIds->diff($exceptCategoryIds);
                # За исключением брендов
                $exceptBrandIds = $this->getExceptBrandsForDiscount($discount);
                # За исключением офферов
                $exceptOfferIds = $this->getExceptOffersForDiscount($discount);
                # Отбираем нужные офферы
                $offerIds = $this->filterForCategory(
                    $categoryIds,
                    $exceptBrandIds,
                    $exceptOfferIds,
                    $discount->merchant_id
                );
                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
            case Discount::DISCOUNT_TYPE_DELIVERY:
                // Если используется бесплатная доставка (например, по промокоду), то не использовать скидку
                if ($this->input->freeDelivery) {
                    break;
                }

                /**
                 * Считаются только возможные скидки.
                 * Берем все доставки, для которых необходимо посчитать только возможную скидку,
                 * по очереди применяем скидки (откатывая предыдущие изменения, т.к. нельзя выбрать сразу две доставки),
                 */
                $currentDeliveryId = $this->input->deliveries['current']['id'] ?? null;
                $this->input->deliveries['items']->transform(function ($delivery) use ($discount, $currentDeliveryId, &$change) {
                    $deliveryApplier = new DeliveryApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                    $deliveryApplier->setCurrentDelivery($delivery);
                    $changedPrice = $deliveryApplier->apply($discount);
                    $currentDelivery = $deliveryApplier->getModifiedCurrentDelivery();

                    if ($currentDelivery['id'] === $currentDeliveryId) {
                        $change = $changedPrice;
                        $this->input->deliveries['current'] = $currentDelivery;
                    }
                    return $currentDelivery;
                });

                break;
            case Discount::DISCOUNT_TYPE_CART_TOTAL:
                $basketApplier = new BasketApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $change = $basketApplier->apply($discount);
                $this->basketItemsByDiscounts = $basketApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $basketApplier->getModifiedInputBasketItems();
                break;
            # Скидка на мастер-классы
            case Discount::DISCOUNT_TYPE_MASTERCLASS:
                $ticketTypeIds = $discount->publicEvents
                    ->pluck('ticket_type_id')
                    ->toArray();

                $offerIds = $this->input->basketItems
                    ->whereIn('ticket_type_id', $ticketTypeIds)
                    ->pluck('offer_id');

                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
            case Discount::DISCOUNT_TYPE_ANY_MASTERCLASS:
                $offerIds = $this->input->basketItems
                    ->whereStrict('product_id', null)
                    ->pluck('offer_id');

                $offerApplier = new OfferApplier($this->input, $this->basketItemsByDiscounts, $this->appliedDiscounts);
                $offerApplier->setOfferIds($offerIds);
                $change = $offerApplier->apply($discount);
                $this->basketItemsByDiscounts = $offerApplier->getModifiedBasketItemsByDiscounts();
                $this->input->basketItems = $offerApplier->getModifiedInputBasketItems();
                break;
        }

        // Добавляем все скидки к примененным.
        // Даже те, что не повлияли на цену, т.к. они тоже участвовали в подсчете общей скидки
//        if ($change > 0) {
        $this->appliedDiscounts->put($discount->id, [
            'discountId' => $discount->id,
            'change' => $change,
            'conditions' => $discount->conditions->pluck('type') ?? collect(),
            'summarizable_with_all' => $discount->summarizable_with_all,
        ]);
//        }

        return $change ?: false;
    }

    protected function getExceptOffersForDiscount(Discount $discount): Collection
    {
        return $discount
            ->offers
            ->where('except', true)
            ->pluck('offer_id');
    }

    protected function getExceptBrandsForDiscount(Discount $discount): Collection
    {
        return $discount
            ->brands
            ->where('except', true)
            ->pluck('brand_id');
    }

    protected function getExceptCategoriesForDiscount(Discount $discount): Collection
    {
        return $discount
            ->categories
            ->where('except', true)
            ->pluck('category_id');
    }

    /**
     * Совместимы ли скидки (даже если они не пересекаются)
     */
    protected function isCompatibleDiscount(Discount $discount): bool
    {
        return !$this->appliedDiscounts->has($discount->id);
    }

    /**
     * Откатывает все примененные скидки
     */
    protected function rollback(): self
    {
        $this->appliedDiscounts = collect();
        $this->basketItemsByDiscounts = collect();
        $this->output->appliedDiscounts = collect();

        $basketItems = collect();
        foreach ($this->input->basketItems as $basketItem) {
            $basketItem['price'] = $basketItem['cost'] ?? $basketItem['price'];
            unset($basketItem['discount']);
            unset($basketItem['cost']);
            $basketItems->put($basketItem['id'], $basketItem);
        }
        $this->input->basketItems = $basketItems;

        $deliveries = collect();
        foreach ($this->input->deliveries['items'] as $delivery) {
            $delivery['price'] = $delivery['cost'] ?? $delivery['price'];
            unset($delivery['discount']);
            unset($delivery['cost']);
            $deliveries->put($delivery['id'], $delivery);
        }
        $this->input->deliveries['items'] = $deliveries;

        return $this;
    }

    /**
     * Сортируем в порядке: бандлы > скидка по промо-коду > скидка на товар > скидка на корзину -> скидка на доставку
     */
    protected function sort(): self
    {
        /** @var Collection|Discount[] $discounts */
        $discounts = $this->possibleDiscounts->sortBy(fn(Discount $discount) => $discount->value_type === Discount::DISCOUNT_VALUE_TYPE_RUB);

        if ($promoCodeDiscountId = $this->input->promoCodeDiscount->id ?? null) {
            [$appliedPromocodeDiscounts, $discounts] = $discounts->partition('id', $promoCodeDiscountId);
        }
        [$deliveryDiscounts, $discounts] = $discounts->partition('type', Discount::DISCOUNT_TYPE_DELIVERY);
        [$promocodeDiscounts, $discounts] = $discounts->partition('promo_code_only', true);
        [$bundleDiscounts, $discounts] = $discounts->partition(
            fn($discount) => in_array($discount->type, [Discount::DISCOUNT_TYPE_BUNDLE_OFFER, Discount::DISCOUNT_TYPE_BUNDLE_MASTERCLASS])
        );
        [$cartTotalDiscounts, $discounts] = $discounts->partition('type', Discount::DISCOUNT_TYPE_CART_TOTAL);
        [$nonCatalogDiscounts, $catalogDiscounts] = $discounts->partition(function (Discount $discount) {
            return $discount->conditions->where('type', '!=', DiscountCondition::DISCOUNT_SYNERGY)->isNotEmpty();
        });

        $this->possibleDiscounts = $bundleDiscounts
            ->merge($appliedPromocodeDiscounts ?? [])
            ->merge($catalogDiscounts)
            ->merge($promocodeDiscounts)
            ->merge($nonCatalogDiscounts)
            ->merge($cartTotalDiscounts)
            ->merge($deliveryDiscounts);

        // сортируем скидки таким образом, чтобы первыми были скидки с максимальным приоритетом,
        // а затем скидки по убыванию значения(value)
        $possibleDiscountsPercentType = $this->possibleDiscounts->filter(function (Discount $discount) {
            return $discount->value_type === Discount::DISCOUNT_VALUE_TYPE_PERCENT;
        });
        $this->possibleDiscounts = $this->possibleDiscounts->sort(function ($a, $b) {
            if ($a['max_priority'] == $b['max_priority']) {
                return $b['value'] - $a['value'];
            }

            return $b['value'] - $a['value'];
        });
        Log::info('$this->possibleDiscounts', $this->possibleDiscounts->toArray());
        return $this;
    }

    /**
     * Фильтрует все актуальные скидки и оставляет только те, которые можно применить
     */
    protected function filter(): self
    {
        $this->possibleDiscounts = $this->discounts->filter(function (Discount $discount) {
            return $this->checkDiscount($discount);
        })->values();

        $conditionCheckers = [
            new DiscountConditionChecker($this->input),
            new DifferentProductsCountChecker($this->input),
        ];

        foreach ($conditionCheckers as $conditionChecker) {
            $this->possibleDiscounts = $this->possibleDiscounts->filter(function (Discount $discount) use ($conditionChecker) {
                if ($discount->conditions->isNotEmpty()) {
                    return $conditionChecker->check($discount, $this->getCheckingConditions());
                }

                return true;
            })->values();
        }

        return $this->compileSynegry();
    }

    /**
     * Генерирует временные DiscountCondition для скидок, которые суммируются со всеми (summarizable_with_all)
     */
    protected function compileSynegry(): self
    {
        [$summarizableWithAll, $otherDiscounts] = $this->possibleDiscounts->partition('summarizable_with_all', true);
        if ($summarizableWithAll->count() == 0) {
            return $this;
        }

        /** @var Discount $summarizableDiscount */
        foreach ($summarizableWithAll as $summarizableDiscount) {
            /** @var Discount $discount */
            foreach ($otherDiscounts as $discount) {

                /** @var DiscountCondition $condition */
                $condition = $discount->conditions->firstWhere('type', DiscountCondition::DISCOUNT_SYNERGY);

                if (!$condition) {
                    /** @var DiscountCondition $condition */
                    $condition = new DiscountCondition();
                    $condition->type = DiscountCondition::DISCOUNT_SYNERGY;
                    $discount->conditions->add($condition);
                }

                $condition->condition = array_merge_recursive($condition->condition ?? [], [
                    DiscountCondition::FIELD_SYNERGY => [$summarizableDiscount->id],
                ]);
            }
        }

        return $this;
    }

    /**
     * Можно ли применить данную скидку (независимо от других скидок)
     */
    protected function checkDiscount(Discount $discount): bool
    {
        return $this->checkType($discount)
            && $this->checkCustomerRole($discount)
            && $this->checkSegment($discount);
    }

    /**
     * Условия скидок, которые должны проверяться
     */
    protected function getCheckingConditions(): array
    {
        return [
            DiscountConditionModel::FIRST_ORDER,
            DiscountConditionModel::MIN_PRICE_ORDER,
            DiscountConditionModel::MIN_PRICE_BRAND,
            DiscountConditionModel::MIN_PRICE_CATEGORY,
            DiscountConditionModel::EVERY_UNIT_PRODUCT,
            DiscountConditionModel::DELIVERY_METHOD,
            DiscountConditionModel::PAY_METHOD,
            DiscountConditionModel::REGION,
            DiscountConditionModel::CUSTOMER,
            DiscountConditionModel::ORDER_SEQUENCE_NUMBER,
            DiscountConditionModel::BUNDLE,
            DiscountConditionModel::DISCOUNT_SYNERGY,
            DiscountConditionModel::DIFFERENT_PRODUCTS_COUNT,
            DiscountConditionModel::MERCHANT
        ];
    }

    /**
     * Проверяет все необходимые условия по свойству "Тип скидки"
     */
    protected function checkType(Discount $discount): bool
    {
        return match ($discount->type) {
            Discount::DISCOUNT_TYPE_OFFER => $this->checkOffers($discount),
            Discount::DISCOUNT_TYPE_BUNDLE_OFFER, Discount::DISCOUNT_TYPE_BUNDLE_MASTERCLASS => $this->checkBundles($discount),
            Discount::DISCOUNT_TYPE_BRAND => $this->checkBrands($discount),
            Discount::DISCOUNT_TYPE_CATEGORY => $this->checkCategories($discount),
            Discount::DISCOUNT_TYPE_DELIVERY => isset($this->input->deliveries['current']['price']),
            Discount::DISCOUNT_TYPE_MASTERCLASS => $this->input->ticketTypeIds->isNotEmpty()
                && $this->checkPublicEvents($discount),
            Discount::DISCOUNT_TYPE_CART_TOTAL, Discount::DISCOUNT_TYPE_ANY_OFFER, Discount::DISCOUNT_TYPE_ANY_BUNDLE,
            Discount::DISCOUNT_TYPE_ANY_BRAND, Discount::DISCOUNT_TYPE_ANY_CATEGORY,
            Discount::DISCOUNT_TYPE_ANY_MASTERCLASS => $this->input->basketItems->isNotEmpty(),
            default => false,
        };
    }

    /**
     * Проверяет доступность применения скидки на офферы
     */
    protected function checkOffers(Discount $discount): bool
    {
        return $discount->type === Discount::DISCOUNT_TYPE_OFFER
            && $discount->offers->where('except', '=', false)->isNotEmpty();
    }

    /**
     * Проверяет доступность применения скидок-бандлов
     */
    protected function checkBundles(Discount $discount): bool
    {
        return in_array($discount->type, [Discount::DISCOUNT_TYPE_BUNDLE_OFFER, Discount::DISCOUNT_TYPE_BUNDLE_MASTERCLASS])
            && $discount->bundleItems->isNotEmpty();
    }

    /**
     * Проверяет доступность применения скидки на бренды
     */
    protected function checkBrands(Discount $discount): bool
    {
        return $discount->type === Discount::DISCOUNT_TYPE_BRAND
            && $discount->brands->filter(fn($brand) => !$brand['except'])->isNotEmpty();
    }

    /**
     * Проверяет доступность применения скидки на категории
     */
    protected function checkCategories(Discount $discount): bool
    {
        return $discount->type === Discount::DISCOUNT_TYPE_CATEGORY
            && $discount->categories->isNotEmpty();
    }

    /**
     * Проверяет доступность применения скидки на мастер-классы
     */
    protected function checkPublicEvents(Discount $discount): bool
    {
        return $discount->type === Discount::DISCOUNT_TYPE_MASTERCLASS
            && $discount->publicEvents->isNotEmpty();
    }

    protected function checkCustomerRole(Discount $discount): bool
    {
        return $discount->roles->pluck('role_id')->isEmpty() ||
            (
                isset($this->input->customer['roles'])
                && $discount->roles->pluck('role_id')->intersect($this->input->customer['roles'])->isNotEmpty()
            );
    }

    protected function checkSegment(Discount $discount): bool
    {
        // Если отсутствуют условия скидки на сегмент
        if ($discount->segments->pluck('segment_id')->isEmpty()) {
            return true;
        }

        return isset($this->input->customer['segment'])
            && $discount->segments->contains('segment_id', $this->input->customer['segment']);
    }

    /**
     * Существует ли хотя бы одна скидка с одним из типов скидки ($types)
     *
     * метод не совсем соответсвует названию, в случае если существует скидка с таким типом,
     * то возвращается false, хотя по названию должно возвращаться true
     */
    protected function existsAnyTypeInDiscounts(array $types): bool
    {
        return $this->discounts->groupBy('type')
            ->keys()
            ->intersect($types)
            ->isEmpty();
    }
}
