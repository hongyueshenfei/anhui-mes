<template>
  <div class="empty-state-panel" :class="`is-${kind}`" role="status" aria-live="polite">
    <span class="empty-state-icon" aria-hidden="true">
      <el-icon>
        <WarningFilled v-if="kind === 'error'" />
        <Loading v-else-if="kind === 'loading'" />
        <Search v-else-if="kind === 'filtered'" />
        <DocumentAdd v-else />
      </el-icon>
    </span>
    <div class="empty-state-copy">
      <strong>{{ title }}</strong>
      <span>{{ description }}</span>
    </div>
    <el-button
      v-if="actionLabel"
      class="empty-state-action"
      :type="kind === 'error' ? 'primary' : 'default'"
      :plain="kind === 'error'"
      size="small"
      @click="$emit('action')"
    >
      {{ actionLabel }}
    </el-button>
  </div>
</template>

<script setup lang="ts">
import { DocumentAdd, Loading, Search, WarningFilled } from '@element-plus/icons-vue'

withDefaults(defineProps<{
  kind?: 'empty' | 'filtered' | 'error' | 'loading'
  title: string
  description: string
  actionLabel?: string
}>(), {
  kind: 'empty',
  actionLabel: '',
})

defineEmits<{ action: [] }>()
</script>

<style scoped>
.empty-state-panel {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  min-height: 112px;
  padding: 18px 24px;
  color: var(--hy-text);
  text-align: left;
}

.empty-state-icon {
  display: grid;
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 9px;
  color: var(--hy-blue);
  background: #eaf3fa;
  font-size: 18px;
}

.is-filtered .empty-state-icon {
  color: #57718a;
  background: #eef2f5;
}

.is-error .empty-state-icon {
  color: var(--hy-red);
  background: #fff0ee;
}

.is-loading .empty-state-icon {
  color: var(--hy-blue);
  background: #eaf3fa;
  animation: empty-spin 1.2s linear infinite;
}

.empty-state-copy {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-width: 440px;
}

.empty-state-copy strong {
  color: var(--hy-ink);
  font-size: 13px;
  font-weight: 650;
}

.empty-state-copy span {
  color: var(--hy-muted);
  font-size: 12px;
  line-height: 1.55;
}

.empty-state-action {
  flex: 0 0 auto;
  margin-left: 8px;
}

@keyframes empty-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .is-loading .empty-state-icon {
    animation: none;
  }
}

@media (max-width: 600px) {
  .empty-state-panel {
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 10px 12px;
    padding: 16px;
  }

  .empty-state-copy {
    flex: 1 1 calc(100% - 52px);
  }

  .empty-state-action {
    margin-left: 48px;
  }
}
</style>
