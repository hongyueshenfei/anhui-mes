<template>
  <div class="page">
    <van-nav-bar title="绑定工号" left-arrow @click-left="$router.back()" />

    <van-cell-group inset style="margin-top: 24px">
      <van-field v-model="employeeNo" label="工号" placeholder="输入员工工号（管理员创建）" required />
      <van-field v-model="password" label="密码" type="password" placeholder="可选：开通账号密码登录" />
    </van-cell-group>

    <div class="actions">
      <van-button type="primary" block round :loading="loading" @click="onBind">确认绑定</van-button>
    </div>
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
