<template>
  <div class="page">
    <van-nav-bar title="绑定工号" left-arrow @click-left="$router.back()" />

    <main class="content">
      <div class="intro">
        <span class="eyebrow">FIRST-TIME SETUP</span>
        <h1>绑定工号</h1>
        <p>将微信身份与员工账号关联，绑定成功后即可查看对应生产任务。</p>
      </div>
      <van-cell-group inset class="form-card">
        <van-field v-model="employeeNo" label="员工工号" placeholder="输入管理员创建的工号" required />
        <van-field v-model="password" label="账号密码" type="password" placeholder="如账号已设置密码，请填写" />
      </van-cell-group>

      <div class="actions">
        <van-button type="primary" block round :loading="loading" @click="onBind">确认绑定</van-button>
        <p>如尚未创建账号，请联系管理员开通。</p>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showToast } from 'vant'
import http from '@/api/axios'

const route = useRoute()
const router = useRouter()

const employeeNo = ref('')
const password = ref('')
const loading = ref(false)

async function onBind(): Promise<void> {
  if (!employeeNo.value) {
    showToast('请输入工号')
    return
  }
  loading.value = true
  try {
    const payload = {
      channel: 'wx',
      openid: (route.query.openid as string) || `openid_${Date.now()}`,
      employee_no: employeeNo.value,
      password: password.value || undefined,
    }
    const { data } = await http.post('/api/auth/bind-employee', payload)
    localStorage.setItem('hy_mobile_token', data.data.token)
    localStorage.setItem('hy_mobile_user', JSON.stringify(data.data.user ?? {}))
    showToast('绑定成功，已自动登录')
    router.push('/tasks')
  } catch (error: any) {
    showToast(error?.response?.data?.message ?? '绑定失败，请检查后端服务')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.content { width: min(100%, 480px); margin: 0 auto; padding: 28px 0 40px; }
.intro { padding: 8px 20px 20px; }
.eyebrow { color: var(--hy-blue); font-size: 10px; font-weight: 700; letter-spacing: 0.14em; }
h1 { margin: 7px 0; color: var(--hy-ink); font-size: 22px; }
.intro p, .actions p { margin: 0; color: var(--hy-muted); font-size: 12px; line-height: 1.6; }
.form-card { margin-top: 4px; }
.actions { display: grid; gap: 12px; padding: 20px 20px; }
</style>
