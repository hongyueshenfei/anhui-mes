<template>
  <main class="page">
    <div class="hero">
      <div class="brand-mark" aria-hidden="true">虹</div>
      <div class="eyebrow">HONGYUE · SHOPFLOOR</div>
      <h1>虹越车间端</h1>
      <p>登录后查看当日任务，并完成生产报工。</p>
    </div>

    <section class="auth-panel" aria-label="登录信息">
      <div class="panel-heading">
        <h2>账号验证</h2>
        <span>首次使用需绑定工号</span>
      </div>
      <van-cell-group inset>
        <van-field v-model="openid" label="微信标识" placeholder="请输入微信 openid" />
      </van-cell-group>

      <div class="actions">
        <van-button type="primary" block round :loading="loading" @click="onLogin">微信免密登录</van-button>
        <van-button class="bind-button" block round plain @click="onBind">首次使用 · 绑定工号</van-button>
      </div>
    </section>

    <van-notice-bar class="login-note" left-icon="info-o" text="登录后扫码机台，选择当日固定任务进行日结报工" />
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import http from '@/api/axios'

const router = useRouter()
const openid = ref('')
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
  display: flex;
  min-height: 100vh;
  flex-direction: column;
  justify-content: center;
  padding: 32px 0 max(28px, env(safe-area-inset-bottom));
  background:
    radial-gradient(ellipse at 50% 0, rgba(23, 109, 179, 0.10), transparent 42%),
    var(--hy-canvas);
}

.hero,
.auth-panel,
.login-note {
  width: min(100% - 32px, 420px);
  margin-right: auto;
  margin-left: auto;
}

.hero {
  margin-bottom: 24px;
}

.brand-mark {
  display: grid;
  width: 48px;
  height: 48px;
  margin-bottom: 18px;
  place-items: center;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 14px;
  background: linear-gradient(145deg, #23485f, #176db3);
  color: #fff;
  font-size: 23px;
  font-weight: 700;
  box-shadow: 0 8px 20px rgba(23, 109, 179, 0.18);
}

.eyebrow {
  margin-bottom: 6px;
  color: var(--hy-blue);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.16em;
}

.hero h1 {
  margin: 0;
  color: var(--hy-ink);
  font-size: 25px;
  letter-spacing: 0.02em;
}

.hero p,
.panel-heading span {
  color: var(--hy-muted);
  font-size: 13px;
}

.hero p { margin: 7px 0 0; }
.auth-panel {
  padding: 18px 0 8px;
  border: 1px solid var(--hy-line);
  border-radius: 12px;
  background: var(--hy-surface);
  box-shadow: 0 12px 32px rgba(23, 43, 58, 0.06);
}
.panel-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  padding: 0 18px 12px;
}
.panel-heading h2 { margin: 0; color: var(--hy-ink); font-size: 15px; }
.panel-heading span { font-size: 11px; }
.auth-panel :deep(.van-cell-group--inset) {
  margin: 0 12px;
  border: 0;
  border-radius: 0;
}

.actions {
  display: grid;
  gap: 10px;
  padding: 18px 16px 12px;
}
.bind-button { margin-top: 0; }
.login-note { margin-top: 16px; border-radius: 8px; }
</style>
