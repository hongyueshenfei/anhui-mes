<template>
  <el-alert v-if="visible" type="warning" :closable="false" show-icon class="stale-data-notice">
    <template #title>
      <span>本次读取失败，当前仍显示上次成功的数据。</span>
      <el-button class="retry-button" link type="primary" size="small" @click="$emit('retry')">重新加载</el-button>
    </template>
  </el-alert>
</template>

<script setup lang="ts">
defineProps<{ visible: boolean }>()
defineEmits<{ retry: [] }>()
</script>

<style scoped>
.stale-data-notice {
  margin: 0 0 12px;
}

.retry-button {
  margin-left: 8px;
  vertical-align: baseline;
}
</style>
