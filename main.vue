<template>
    <div class="mt-3" role="tablist">
        Фильтры<br>
        <div class="mb-2">
            <template v-for="filter in phantomicFilters">
                <b-badge pill
                         variant="secondary"
                         class="mr-1"
                         style="cursor: pointer"
                         @click="removeOldFilter(filter.id)"
                >
                    {{ filter.name }}
                    <fa-icon icon="trash-alt"></fa-icon>
                </b-badge>
            </template>
            <template v-for="filter in genericFilters">
                <template v-for="filterValue in filter.values">
                    <b-badge v-if="isChecked(filter.code, filterValue.code)"
                             pill
                             variant="info"
                             class="mr-1"
                    >
                        {{ filterValue.name }}
                    </b-badge>
                </template>
            </template>
            <span v-if="selectedFilterSources && !genericFilters.length">Фильтры для выбранных категорий не найдены</span>
        </div>

        <b-card no-body
                class="mb-1"
                v-for="filter in genericFilters"
                :key="filter.id"
        >
            <b-card-header header-tag="header" class="p-1" role="tab">
                <b-button block href="#" v-b-toggle="'collapse-' + filter.id" variant="info">
                    {{ filter.name ? filter.name : 'Шильдики' }}
                </b-button>
            </b-card-header>
            <b-collapse :id="'collapse-' + filter.id" accordion="my-accordion" role="tabpanel">
                <b-card-body>
                    <b-form-group>
                        <b-form-checkbox-group
                            id="checkbox-group-2"
                            v-model="selectedFilters"
                            name="flavour-2"
                        >
                            <b-form-checkbox
                                v-for="filterValue in filter.values"
                                :value="{
                                        code: filter.code,
                                        value: filterValue.code,
                                    }"
                                :key="filterValue.code"
                            >
                                {{ filterValue.name }}
                            </b-form-checkbox>
                        </b-form-checkbox-group>
                        <b-form-group>
                            <b-button variant="primary" @click="selectAll(filter.id)">Выбрать все</b-button>
                            <b-form-checkbox>
                                Активно на сайте
                            </b-form-checkbox>
                        </b-form-group>
                        <b-form-group class="mt-2 w-25">
                            <b-form-input v-model="sortValue" placeholder="Введите значение сортировки"></b-form-input>
                        </b-form-group>
                    </b-form-group>
                </b-card-body>
            </b-collapse>
        </b-card>
    </div>
</template>

<script>
import Services from '../../../../../scripts/services/services';

export default {
    components: {},
    props: {
        iSelectedFilterSources: Array,
        iSelectedFilters: Array,
        iOldSelectedFilters: Array,
        iProductGroupTypes: Array,
    },

    data() {
        return {
            productGroupTypes: this.iProductGroupTypes,
            selectedFilterSources: this.iSelectedFilterSources,
            selectedFilters: this.filterNormalization(this.iSelectedFilters),
            oldSelectedFilters: this.iOldSelectedFilters,
            filters: {},
        };
    },

    methods: {
        fetchFilters(source) {
            let filterPromise = this.productGroupTypes.find(type => type.code === source)
                ? Services.net().get(this.getRoute('productGroup.getFilters'))
                : Services.net().get(this.getRoute('productGroup.getFiltersByCategory'), {category: source});

            Services.showLoader();

            filterPromise.then((data) => {
                this.$set(this.filters, source, data);
            }).finally(() => {
                Services.hideLoader();
            });
        },

        async getFilters(source) {
            const data = await this.productGroupTypes.find(type => type.code === source)
                ? Services.net().get(this.getRoute('productGroup.getFilters'))
                : Services.net().get(this.getRoute('productGroup.getFiltersByCategory'), {category: source});

            return data;
        },

        groupByCode(array) {
            let result = {};
            array.forEach(item => {
                if (!result.hasOwnProperty(item.code)) {
                    result[item.code] = [];
                }
                result[item.code].push(item);
            });
            return result;
        },
        isChecked(code, value) {
            for (let selectedFilterKey in this.selectedFilters) {
                let selectedFilter = this.selectedFilters[selectedFilterKey];

                if (selectedFilter.code === code && selectedFilter.value === value) {
                    return true;
                }
            }

            return false;
        },
        filterNormalization(rawFilters) {
            if (typeof rawFilters === 'undefined') {
                return [];
            }

            return rawFilters.map(function (filter) {
                return {
                    code: filter.code,
                    value: filter.value,
                };
            });
        },
        removeOldFilter(id) {
            this.oldSelectedFilters = this.oldSelectedFilters.filter(filter => {
                return filter.id !== id;
            });
        },
        selectAll(filterId) {

        },
    },
    computed: {
        phantomicFilters() {
            let genericFilters = this.groupByCode(this.genericFilters);

            return this.oldSelectedFilters.filter(item => {
                if (Object.keys(genericFilters).includes(item.code)) {
                    return !Object.keys(this.groupByCode(genericFilters[item.code][0].values)).includes(item.value);
                }
                return false;
            });
        },

        genericFilters() {
            let allFilters = [];

            Object.values(this.filters).forEach(filtersBySource => {
                filtersBySource.forEach(filter => {
                    allFilters.push(Object.assign({}, filter));
                });
            });

            let groupedFilters = this.groupByCode(allFilters);

            let genericFilters = Object.values(groupedFilters)
                .map(filterGroup => {
                    let allValues = [];
                    filterGroup.forEach(filter => {
                        allValues = allValues.concat(filter.values);
                    });

                    let groupedValues = this.groupByCode(allValues);
                    let genericValues = Object.values(groupedValues);

                    filterGroup[0]['values'] = genericValues.map(values => values[0]);
                    return filterGroup[0];
                });

            return genericFilters.filter(filter => filter.values.length > 0);
        },
    },
    watch: {
        iSelectedFilterSources(val) {
            let arrayDiff = val.filter(source => !this.selectedFilterSources.includes(source));
            let arrayDiffReversed = this.selectedFilterSources.filter(source => !val.includes(source));
            let addedSource = arrayDiff.length > 0 ? arrayDiff[0] : null;
            let removedSource = arrayDiffReversed.length > 0 ? arrayDiffReversed[0] : null;
            if (addedSource) {
                this.fetchFilters(addedSource);
            }
            if (removedSource) {
                this.$delete(this.filters, removedSource);
            }
            this.selectedFilterSources = val;
        },
        selectedFilters(val) {
            let oldFilters = this.phantomicFilters.map(filter => {
                return {
                    'code': filter.code,
                    'value': filter.value,
                }
            });
            let allFilters = val.concat(oldFilters);
            this.$emit('update', allFilters);
        },
        genericFilters(val) {
            let groupedGenericFilters = this.groupByCode(val);
            this.selectedFilters = this.selectedFilters.filter(filter => {
                if (Object.keys(groupedGenericFilters).includes(filter.code)) {
                    let groupedFilterValues = this.groupByCode(groupedGenericFilters[filter.code][0].values);
                    return Object.keys(groupedFilterValues).includes(filter.value);
                }
                return false;
            });

            this.oldSelectedFilters = this.oldSelectedFilters.filter(filter => {
                return Object.keys(groupedGenericFilters).includes(filter.code);
            });
        },
        phantomicFilters(val) {
            let oldFilters = val.map(filter => {
                return {
                    'code': filter.code,
                    'value': filter.value,
                }
            });
            let allFilters = oldFilters.concat(this.selectedFilters);
            this.$emit('update', allFilters);
        }
    },

    async mounted() {
        let filters = {};

        Services.showLoader();

        for (const source of this.selectedFilterSources) {
            filters[source] = await this.getFilters(source);
        }

        this.filters = filters;

        Services.hideLoader();
    }
}
</script>
