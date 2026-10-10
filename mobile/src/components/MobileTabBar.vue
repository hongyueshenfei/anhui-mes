<template>
  <van-tabbar route fixed safe-area-inset-bottom>
    <van-tabbar-item replace to="/tasks" icon="clock-o">今日任务</van-tabbar-item>
    <van-tabbar-item v-if="canReport" replace to="/report/qr" icon="scan">扫码报工</van-tabbar-item>
    <van-tabbar-item replace to="/reports/mine" icon="records">我的填报</van-tabbar-item>
  </van-tabbar>
</template>

<script setup lang="ts">
defineProps<{ canReport: boolean }>()
</script>
