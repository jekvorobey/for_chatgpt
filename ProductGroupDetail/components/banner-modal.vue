<template>
    <transition name="modal">
        <modal :close="closeModal" v-if="isModalOpen(modalName)">
            <div slot="header">
                {{ isCreatingAction ? 'Создание': 'Редактирование' }} баннера
            </div>
            <div slot="body">
                <b-form @submit.prevent="submit">
                    <banner-edit-form
                            @update="updateBanner"
                            :iBanner="banner"
                            :iBannerTypes="bannerTypes"
                            :iBannerButtonTypes="bannerButtonTypes"
                            :iBannerButtonLocations="bannerButtonLocations"
                            :iBannerImages="bannerImages"
                    ></banner-edit-form>

                    <b-button type="submit" variant="dark">Применить</b-button>
                </b-form>
            </div>
        </modal>
    </transition>
</template>

<script>
    import modal from '../../../../components/controls/modal/modal.vue';
    import modalMixin from '../../../../mixins/modal.js';
    import BannerEditForm from "../../../../components/banner-edit-form/banner-edit-form.vue";
    import Services from "../../../../../scripts/services/services";

    export default {
        components: {
            BannerEditForm,
            modal,
        },
        mixins: [modalMixin],
        props: {
            modalName: String,
            id: null,
        },
        data() {
            return {
                banner: {},
                bannerTypes: [],
                bannerButtonTypes: [],
                bannerButtonLocations: [],
                bannerImages: {},
            };
        },
        methods: {
            submit() {
                if (this.isCreatingAction) {
                    this.create();
                } else {
                    this.update();
                }
            },
            update() {
                let model = this.banner;

                Services.net()
                    .put(this.getRoute('banner.update', {id: this.banner.id,}), {}, model)
                    .then((data) => {
                        this.$emit('accept', this.banner.id);
                    })
                    .catch((e) => {
                        console.error(e);
                    });
            },
            create() {
                let model = this.banner;

                Services.net()
                    .post(this.getRoute('banner.create'), {}, model)
                    .then((data) => {
                        this.$emit('accept', data);
                    })
                    .catch(() => {
                        console.error(e);
                    });
            },
            accept() {
                this.$emit('accept', this.banner.id);
            },
            initBanners() {
                Services.net()
                    .get(this.getRoute('banner.initialData'), {id: this.id})
                    .then((data) => {
                        this.banner = data.banner || {}; // зависит от id
                        this.bannerTypes = data.bannerTypes;
                        this.bannerButtonTypes = data.bannerButtonTypes;
                        this.bannerButtonLocations = data.bannerButtonLocations;
                        this.bannerImages = data.bannerImages;
                    })
                    .catch((e) => {
                        console.error(e);
                    });
            },
            updateBanner(banner) {
                this.banner = banner;
            },
        },
        computed: {
            menuItem: {
                get() {
                    return this.model;
                },
                set(value) {
                    this.$emit('update:model', value);
                }
            },
            isCreatingAction() {
                return !this.banner || !this.banner.id;
            }
        },
        mounted() {
            this.initBanners()
        }
    }
</script>
