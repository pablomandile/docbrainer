<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();
</script>

<!--
  En una sola línea: el encabezado tiene alto fijo y, con el nombre de un
  proyecto largo, la ruta ocupaba tres renglones y se salía por arriba. En el
  celular se ve solo el último nivel; el nombre largo se corta con «…».
-->
<template>
    <Breadcrumb class="min-w-0">
        <BreadcrumbList class="flex-nowrap">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem
                    :class="
                        index === breadcrumbs.length - 1
                            ? 'min-w-0'
                            : 'hidden shrink-0 sm:inline-flex'
                    "
                >
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage
                            class="block truncate"
                            :title="item.title"
                        >
                            {{ item.title }}
                        </BreadcrumbPage>
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link :href="item.href">{{ item.title }}</Link>
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    class="hidden sm:block"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
