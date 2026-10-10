<template>
  <div class="primary-navigation">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">虹</span>
      <div class="brand-copy">
        <strong>虹越智造平台</strong>
        <span>生产 · 排程 · 质量</span>
      </div>
    </div>

    <nav aria-label="主导航" class="navigation-body">
      <div class="navigation-label">业务导航</div>
      <el-menu
        :default-active="activePath"
        router
        background-color="transparent"
        text-color="#c3d0d9"
        active-text-color="#ffffff"
        @select="emit('select', $event)"
      >
        <el-sub-menu v-for="group in groups" :key="group.key" :index="group.key">
          <template #title>
            <el-icon><component :is="group.icon" /></el-icon>
            <span>{{ group.title }}</span>
          </template>
          <el-menu-item v-for="item in group.children" :key="item.path" :index="item.path">
            <el-icon><component :is="item.icon" /></el-icon>
            <span>{{ item.title }}</span>
          </el-menu-item>
        </el-sub-menu>
      </el-menu>
    </nav>

    <div class="navigation-footer">
      <span class="footer-indicator" aria-hidden="true" />
      <span>面向生产现场的协同工作台</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { Component } from 'vue'

interface NavigationItem {
  path: string
  title: string
  icon: Component
  perm: string
}

interface NavigationGroup {
  key: string
  title: string
  icon: Component
  children: NavigationItem[]
}

defineProps<{
  groups: NavigationGroup[]
  activePath: string
}>()

const emit = defineEmits<{
  select: [path: string]
}>()
</script>

<style scoped>
.primary-navigation {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  color: #eff4f7;
  background: var(--hy-ink, #172b3a);
}

.brand {
  display: flex;
  align-items: center;
  gap: 11px;
  min-height: 76px;
  padding: 15px 17px;
  border-bottom: 1px solid rgba(220, 232, 239, 0.12);
}

.brand-mark {
  display: grid;
  width: 37px;
  height: 37px;
  flex: 0 0 auto;
  place-items: center;
  border: 1px solid rgba(185, 213, 230, 0.55);
  border-radius: 9px;
  color: #f5fbff;
  background: #244358;
  font-size: 18px;
  font-weight: 700;
}

.brand-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  line-height: 1.35;
}

.brand-copy strong {
  overflow: hidden;
  color: #f6f9fb;
  font-size: 14px;
  font-weight: 650;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.brand-copy span {
  color: #9fb1bd;
  font-size: 11px;
}

.navigation-body {
  flex: 1;
  padding: 14px 10px 10px;
}

.navigation-label {
  padding: 0 10px 8px;
  color: #8fa3b0;
  font-size: 11px;
}

.primary-navigation :deep(.el-menu) {
  border-right: 0;
}

.primary-navigation :deep(.el-sub-menu__title),
.primary-navigation :deep(.el-menu-item) {
  height: 41px;
  margin: 2px 0;
  border-radius: 6px;
  line-height: 41px;
  transition: background-color 120ms ease, color 120ms ease;
}

.primary-navigation :deep(.el-sub-menu__title:hover),
.primary-navigation :deep(.el-menu-item:hover) {
  color: #fff;
  background: rgba(226, 239, 247, 0.09);
}

.primary-navigation :deep(.el-menu-item.is-active) {
  position: relative;
  color: #fff;
  background: #28516b;
  font-weight: 600;
}

.primary-navigation :deep(.el-menu-item.is-active::before) {
  position: absolute;
  top: 9px;
  bottom: 9px;
  left: 0;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: #74c2ae;
  content: '';
}

.navigation-footer {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 13px 18px 16px;
  border-top: 1px solid rgba(220, 232, 239, 0.12);
  color: #9fb1bd;
  font-size: 11px;
}

.footer-indicator {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #72b7a3;
}

@media (prefers-reduced-motion: reduce) {
  .primary-navigation :deep(.el-sub-menu__title),
  .primary-navigation :deep(.el-menu-item) {
    transition: none;
  }
}
</style>
