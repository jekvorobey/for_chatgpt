<template>
    <layout-main back>
        <b-form @submit.prevent="submit">
            <b-form-group
                    label="Наименование*"
                    label-for="group-name"
            >
                <b-form-input
                        id="group-name"
                        v-model="productGroup.name"
                        type="text"
                        required
                        placeholder="Введите наименование"
                />
            </b-form-group>

            <b-form-group
                    label="Символьный код*"
                    label-for="group-code"
            >
                <b-form-input
                        id="group-code"
                        v-model="productGroup.code"
                        type="text"
                        required
                        placeholder="Введите символьный код"
                />
            </b-form-group>

            <b-form-group
                    label="Активность"
                    label-for="group-active"
            >
                <b-form-checkbox
                        id="group-active"
                        v-model="productGroup.active"
                        :value="1"
                        :unchecked-value="0"
                        required
                />
            </b-form-group>

            <b-form-group
                    label="Наличие в списках"
                    label-for="group-is-shown"
            >
                <b-form-checkbox
                        id="group-is-shown"
                        v-model="productGroup.is_shown"
                        :value="1"
                        :unchecked-value="0"
                        required
                />
            </b-form-group>

            Превью картинка*<br>
            <img v-if="previewPhoto"
                 :src="previewPhoto.url"
                 class="preview-photo"
            >
            <file-input
                    @uploaded="onUploadPreviewPhoto"
                    class="preview-photo-input"
            />

            Баннер<br>
            <b-button v-show="productGroup.banner_id" class="my-3" variant="outline-primary" @click.prevent="openBannerModal">Изменить баннер</b-button>
            <b-button v-show="productGroup.banner_id" class="my-3" variant="outline-primary" @click.prevent="removeBanner">Удалить баннер</b-button>
            <b-button v-show="!productGroup.banner_id" class="my-3" variant="outline-primary" @click.prevent="openBannerModal">Создать баннер</b-button>

            <b-form-group
                    label="Тип*"
                    label-for="group-type"
            >
                <b-form-select
                        v-model="productGroup.type_id"
                        id="group-type"
                >
                    <b-form-select-option
                            :value="type.id"
                            v-for="type in productGroupTypes"
                            :key="type.id"
                    >
                        {{ type.name }}
                    </b-form-select-option>
                </b-form-select>
            </b-form-group>

            <div>
              <div class="mt-1 mb-1">
                <span>Категория</span>
                <button @click="addCategory()"
                        class="btn btn-outline-info h-100 ml-3"
                        :disabled="categories.length < 2"
                        type="button">
                  <fa-icon icon="plus"></fa-icon>
                </button>
              </div>
              <div v-for="(categoryField, index) in categoryFields" class="mt-1 d-flex">
                  <div class="col-11 pl-0">
                      <b-form-select
                          v-model="categoryFields[index]"
                          id="group-category">
                        <b-form-select-option
                            :value="category.code"
                            v-for="category in categories"
                            v-bind:key="category.id">
                          {{ getFullCategoryName(category) }}
                        </b-form-select-option>
                      </b-form-select>
                  </div>
                  <div>
                      <button @click="removeCategory(index)"
                              class="btn btn-outline-secondary float-right h-100"
                              type="button">
                        <fa-icon icon="trash-alt"></fa-icon>
                      </button>
                  </div>
              </div>
            </div>

            <select-filters
                    v-if="(selectedCategories.length > 0 || !basedOnCategory)"
                    :i-old-selected-filters="oldSelectedFilters"
                    :i-selected-filters="selectedFilters"
                    :i-selected-filter-sources="selectedFilterSources"
                    :i-product-group-types="productGroupTypes"
                    @update="(data) => selectedFilters = data"
            >
            </select-filters>

            <select-products
                    :i-selected-product-ids="pluckSelectedProductIds()"
                    @add="onAddToSelectedProducts"
                    @delete="onDeleteFromSelectedProducts"
            >
            </select-products>

            <b-button type="submit" class="mt-3" variant="dark" v-if="canUpdate(blocks.content)">{{ isCreatingMode ? 'Создать' : 'Обновить' }}</b-button>
        </b-form>

        <banner-modal modal-name="FormTheme" @accept="onModalAccept" :id="productGroup.banner_id"/>
    </layout-main>
</template>

<script>
    import {mapActions} from 'vuex';
    import Services from '../../../../scripts/services/services';
    import FileInput from '../../../components/controls/FileInput/FileInput.vue';
    import SelectFilters from './components/select-filters.vue';
    import SelectProducts from './components/select-products.vue';
    import modalMixin from '../../../mixins/modal';
    import BannerModal from './components/banner-modal.vue';

    export default {
        mixins: [modalMixin],
        components: {
            SelectFilters,
            SelectProducts,
            FileInput,
            BannerModal,
        },
        props: {
            iProductGroup: {},
            iProductGroupTypes: {},
            iProductGroupImages: {},
            iCategories: {},
            options: {}
        },
        data() {
            return {
                productGroup: this.normalizeProductGroup(this.iProductGroup),
                productGroupTypes: this.iProductGroupTypes,
                productGroupImages: this.iProductGroupImages,
                categories: this.iCategories,
                selectedFilters: this.iProductGroup.filters || [],
                oldSelectedFilters: this.iProductGroup.filters || [],
                selectedProductIds: [],
                selectedProducts: this.iProductGroup.products || [],
                categoryFields: this.setSelectedCategories(this.iProductGroup),
            };
        },

        methods: {
            ...mapActions({
                showMessageBox: 'modal/showMessageBox',
            }),
            normalizeProductGroup(source) {
                return {
                    id: source.id ? source.id : null,
                    name: source.name ? source.name : null,
                    code: source.code ? source.code : null,
                    active: source.active ? source.active : false,
                    is_shown: source.is_shown ? source.is_shown : false,
                    type_id: source.type_id ? source.type_id : null,
                    preview_photo_id: source.preview_photo_id ? source.preview_photo_id : null,
                    banner_id: source.banner_id ? source.banner_id : null,
                    category_code:  source.category_code ? source.category_code : null,
                };
            },
            setSelectedCategories(source) {
                return (source.category_code && source.category_code.length > 0) ? source.category_code : [''];
            },
            submit() {
                if (this.isCreatingMode) {
                    this.create();
                }
                else {
                    this.update();
                }
            },
            update() {
                let model = this.productGroup;
                model.filters = this.selectedFilters;
                model.products = this.selectedProducts;
                model.category_code = model.products.length ? null : this.selectedCategories;

                Services.net()
                    .put(this.getRoute('productGroup.update', {id: this.productGroup.id,}), {}, model)
                    .then((data) => {
                        this.showMessageBox({title: 'Изменения сохранены'});
                        window.location.href = this.route('productGroup.listPage');
                    })
                    .catch(() => {
                        this.showMessageBox({title: 'Ошибка', text: 'Попробуйте позже'});
                    });
            },
            create() {
                let model = this.productGroup;
                model.filters = this.selectedFilters;
                model.products = this.selectedProducts;
                model.category_code = model.products.length ? null : this.selectedCategories;

                Services.net()
                    .post(this.getRoute('productGroup.create'), {}, model)
                    .then((data) => {
                        this.showMessageBox({title: 'Страница сохранена'});
                        window.location.href = this.route('productGroup.listPage');
                    })
                    .catch(() => {
                        this.showMessageBox({title: 'Ошибка', text: 'Попробуйте позже'});
                    });
            },
            onUploadPreviewPhoto(file) {
                this.productGroupImages[file.id] = file;
                this.productGroup.preview_photo_id = file.id;
            },
            onAddToSelectedProducts(productId) {
                this.selectedProducts.push({
                    product_id: productId,
                    product_group_id: this.productGroup.id,
                });
            },
            onDeleteFromSelectedProducts(productId) {
                this.selectedProducts = this.selectedProducts.filter((selectProduct) => {
                    return selectProduct.product_id !== productId
                });
            },
            onModalAccept(id) {
                this.productGroup.banner_id = id;
                this.closeModal('FormTheme');
            },
            openBannerModal() {
                this.openModal('FormTheme');
            },
            removeBanner() {
                this.productGroup.banner_id = null;
            },
            pluckSelectedProductIds() {
                let ids = [];

                for (let productKey in this.selectedProducts) {
                    let product = this.selectedProducts[productKey];
                    ids.push(product.product_id);
                }

                return ids;
            },
            addCategory() {
                this.categoryFields.push('');
            },
            removeCategory(index) {
                this.categoryFields.splice(index, 1);
            },
            getFullCategoryName(category) {
                let branchCategoryNames = [];
                category.ancestors.forEach(function (ancestor) {
                    branchCategoryNames.push(ancestor.name);
                });
                branchCategoryNames.push(category.name);
                return branchCategoryNames.join(' » ');
            },
        },
        computed: {
            previewPhoto() {
                const fileId = this.productGroup.preview_photo_id;

                if (fileId) {
                    const file = this.productGroupImages[fileId];

                    return file ? file : null;
                }

                return null;
            },
            isCreatingMode() {
                return this.productGroup.id === null;
            },
            isBasedOnProducts() {
                return this.selectedProducts.length != 0;
            },
            isBasedOnFilters() {
                return this.selectedFilters.length != 0;
            },
            isBasedOnCategories() {
                return this.selectedCategories.length != 0;
            },
            isNotBasedOn() {
                return !this.isBasedOnProducts && !this.isBasedOnFilters;
            },
            basedOnCategory() {
                if (!this.productGroup.type_id) return true;

                let basedOnCategoryTypeIds = this.productGroupTypes.filter((type) => {
                    return this.options.typesBasedOnCategory.includes(type.code);
                }).map((type) => type.id);
                return basedOnCategoryTypeIds.includes(this.productGroup.type_id);
            },
            availableCategories() {
                return this.categories.filter(category => !this.categoryFields.includes(category.code));
            },
            selectedCategories() {
                return this.categoryFields.filter(category => !!category);
            },
            selectedFilterSources() {
                let additionalFilterSources = this.basedOnCategory
                    ? []
                    : this.productGroupTypes.find(type => type.id === this.productGroup.type_id).code;
                return this.selectedCategories.concat(additionalFilterSources);
            },
        },
    };
</script>

<style scoped>
    .preview-photo {
        width: 300px;
    }

    .preview-photo-input {
        width: 300px;
        margin-top: 10px;
    }
</style>
