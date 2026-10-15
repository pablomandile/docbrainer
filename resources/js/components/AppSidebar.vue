<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpenText, FolderKanban, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { index as usersIndex } from '@/routes/admin/users';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

const page = usePage();

// En el menú mobile (sheet) va el ícono solo; en escritorio, el logo con nombre
const { isMobile } = useSidebar();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Proyectos',
        href: projectsIndex(),
        icon: FolderKanban,
    },
    {
        title: 'Cómo funciona',
        href: '/como-funciona',
        icon: BookOpenText,
    },
    ...(page.props.auth.isAdmin
        ? [
              {
                  title: 'Usuarios',
                  href: usersIndex(),
                  icon: Users,
                  badge: page.props.pendingUsersCount,
              },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="projectsIndex()">
                            <AppLogo :icon-only="isMobile" />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
