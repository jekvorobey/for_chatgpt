<template>
    <div>
        Продукты

        <br>

        <bulk-offer-loader
            :loaded-products="iSelectedProductIds"
            show-report
            :loader="offerLoader"
            :return-mode="offerLoaderReturnModes.PRODUCT"
            @load="onLoadOffers"
        />

        <div v-show="products.length > 0" class="mt-3" style="max-height: 500px; overflow: scroll">
            Добавленные продукты<br>
            <b-card v-for="product in products"
                    no-body
                    class="overflow-hidden"
                    :key="product.id"
            >
                <b-row no-gutters>
                    <b-col md="11">
                        <b-card-body :title="product.name">
                            <b-card-text>
                                Артикул: {{ product.vendor_code }}
                            </b-card-text>
                        </b-card-body>
                    </b-col>
                    <b-col md="1">
                        <b-card-body>
                            <b-button
                                variant="danger"
                                @click="() => {removeProduct(product.id)}"
                            >
                                <fa-icon icon="trash-alt"/>
                            </b-button>
                        </b-card-body>
                    </b-col>
                </b-row>
            </b-card>
        </div>
    </div>
</template>

<script>
    import _chunk from 'lodash/chunk';

    import Services from '../../../../../scripts/services/services';
    import BulkOfferLoader, {
        mode as loaderMode,
        returnMode
    } from '../../../../components/bulk-offer-loader/bulk-offer-loader.vue';

    export default {
        components: {
            BulkOfferLoader
        },

        props: {
            iSelectedProductIds: Array,
        },

        data() {
            return {
                selectedProductIds: this.iSelectedProductIds,
                inputVendorCode: '',
                products: [],
                report: [],

                debounce: null,

                offerLoaderReturnModes: returnMode,
            };
        },

        methods: {
            async offerLoader(mode, codes) {
                const params = {};

                if (mode === loaderMode.OFFER_ID) {
                    params.id = codes;
                } else {
                    params.vendor_code = codes;
                }

                let offers = await Services.net().post(
                    mode === loaderMode.OFFER_ID
                        ? this.getRoute('productGroup.getProductsByOffers')
                        : this.getRoute('productGroup.getProducts'),
                    {},
                    params,
                );

                return offers.map(offer => {
                    return {
                        id: offer.id,
                        vendorCode: offer.vendor_code,
                        productId: offer.product ? offer.product.id : offer.id,
                    };
                });
            },

            onLoadOffers(ids) {
                const internalIds = [ ...this.selectedProductIds ];

                for (const id of ids) {
                    if (!internalIds.includes(id)) {
                        this.$emit('add', id);
                        internalIds.push(id);
                    }
                }

                this.selectedProductIds = [ ...internalIds ];
                this.fetchProducts(this.selectedProductIds);
            },

            removeProduct(id) {
                this.selectedProductIds = this.selectedProductIds.filter((selectProductId) => {
                    return selectProductId !== id
                });

                const index = this.products.findIndex(product => product.id === id);

                if (index >= 0) {
                    this.$delete(this.products, index);
                }

                this.$emit('delete', id);
            },

            async fetchProducts(ids) {
                if (ids && (ids.length > 0)) {
                    const parts = _chunk(ids, 500);
                    let products = [];

                    for (const part of parts) {
                        const data = await Services.net().post(
                            this.getRoute('productGroup.getProducts'),
                            {},
                            {
                                id: part
                            }
                        );

                        products = [
                            ...products,
                            ...data
                        ];
                    }

                    this.products = products;
                } else {
                    this.products = [];
                }
            },
        },

        mounted: function () {
            this.fetchProducts(this.selectedProductIds);
        }
    }
</script>
