<template>
  <div class="page">
    <div class="hero">
      <div class="logo">虹</div>
      <h2>虹越车间端</h2>
      <p>扫码报工 · 今日任务（一期骨架）</p>
    </div>

    <!-- 微信扫码免密（一期骨架：输入 openid 模拟扫码结果） -->
    <van-cell-group inset>
      <van-field v-model="openid" label="openid" placeholder="微信扫码后获得" />
      <van-field v-model="employeeNo" label="工号" placeholder="未绑定用户可先绑工号" />
    </van-cell-group>

    <div class="actions">
      <van-button type="primary" block round :loading="loading" @click="onLogin">微信免密登录</van-button>
      <van-button block round plain style="margin-top: 12px" @click="onBind">首次使用 · 绑定工号</van-button>
    </div>

    <van-notice-bar left-icon="info-o" text="登录后扫码机台，选择当日固定任务进行日结报工" style="margin-top: 24px" />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import http from '@/api/axios'

const router = useRouter()
const openid = ref('')
const employeeNo = ref('')
const loading = ref(false)

async function onLogin(): Promise<void> {
  if (!openid.value) {
    showToast('请填写 openid')
    return
  }
  loading.value = true
  try {
    const { data } = await http.post('/api/auth/wx-login', { openid: openid.value })
    if (data.data?.need_bind) {
      router.push({ path: '/bind', query: { openid: openid.value } })
      return
    }
    localStorage.setItem('hy_mobile_token', data.data.token)
    localStorage.setItem('hy_mobile_user', JSON.stringify(data.data.user ?? {}))
    showToast('登录成功')
    router.push('/tasks')
  } catch (error: any) {
    showToast(error?.response?.data?.message ?? '登录失败，请检查后端服务')
  } finally {
    loading.value = false
  }
}

function onBind(): void {
  router.push({ path: '/bind', query: { openid: openid.value || undefined } })
}
</script>

<style scoped>
.page {
  padding-top: 48px;
}

.hero {
  text-align: center;
  margin-bottom: 32px;
}

.logo {
  width: 56px;
  height: 56px;
  margin: 0 auto 12px;
  border-radius: 14px;
  background: linear-gradient(135deg, #1677ff, #06b6d4);
  color: #fff;
  font-size: 28px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.actions {
  padding: 24px 16px;
}
</style>
