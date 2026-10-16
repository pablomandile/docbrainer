<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Menu } from "lucide-vue-next"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { toggleSidebar } = useSidebar()
</script>

<template>
  <!-- Hamburguesa clásica, siempre el mismo ícono (abierto o cerrado) y con
       44 px de área táctil: es la puerta a todas las secciones en el celular. -->
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('size-11', props.class)"
    @click="toggleSidebar"
  >
    <Menu class="size-6" />
    <span class="sr-only">Abrir o cerrar el menú</span>
  </Button>
</template>
