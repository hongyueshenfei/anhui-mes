<template>
  <el-tag :type="tagType" size="small" effect="light">{{ label }}</el-tag>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { STATUS_LABELS } from '@/utils/capacity'

/**
 * 通用状态标签：按状态值映射中文名与颜色
 */
const props = defineProps<{
  status: string
}>()

const label = computed(() => STATUS_LABELS[props.status] ?? props.status)

const typeMap: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'primary'> = {
  // 机台
  running: 'success',
  stopped: 'info',
  maintenance: 'warning',
  // 产品
  developing: 'info',
  trial: 'warning',
  mass: 'success',
  obsolete: 'danger',
  // BOM/工艺
  draft: 'info',
  published: 'success',
  disabled: 'info',
  // 模具
  in_use: 'success',
  idle: 'warning',
  repair: 'danger',
  scrapped: 'danger',
}

const tagType = computed(() => typeMap[props.status] ?? 'info')
</script>