<template>
  <div class="login-page">
    <div class="login-card">
      <div class="brand">
        <div class="brand-mark">虹</div>
        <h2>虹越智造平台</h2>
        <p>PLM · MES · AI 报价一体化 · 一期工程骨架与数据底座</p>
      </div>

      <el-alert v-if="demoMode" type="warning" :closable="false" show-icon class="demo-tip">
        当前为<strong>演示模式</strong>（模拟数据 · 未连接后端）。账号密码任意演示账号 + 123456 即可进入（admin 全权限）。
      </el-alert>

      <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="onLogin">
        <el-form-item prop="employee_no">
          <el-input v-model="form.employee_no" placeholder="工号（如 admin）" :prefix-icon="User" />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="密码（默认 123456）"
            show-password
            :prefix-icon="Lock"
          />
        </el-form-item>
        <el-button type="primary" class="login-btn" :loading="loading" @click="onLogin">
          登 录
        </el-button>
      </el-form>

      <el-divider><span class="divider-text">其他登录方式</span></el-divider>

      <el-button class="wecom-btn" plain :disabled="true" @click="onWecomLogin">
        <el-icon><ChatDotRound /></el-icon> 企业微信扫码（二期接入）
      </el-button>

      <div class="tips">
        <p>请使用后端数据库中已配置的系统账号登录</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { ChatDotRound, Lock, User } from '@element-plus/icons-vue'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import type { AuthUser } from '@/types'
import { isDemoMode } from '@/config/demo'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const formRef = ref<FormInstance>()
const loading = ref(false)
/** 显式演示模式下使用本地 mock 登录，默认始终请求后端 */
const demoMode = ref(isDemoMode())

const form = ref({
  employee_no: 'admin',
  password: '123456',
})

const rules: FormRules = {
  employee_no: [{ required: true, message: '请输入工号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

onMounted(() => {
  // 仅按显式开关展示提示，默认不显示演示模式
  demoMode.value = isDemoMode()
})

async function onLogin(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  try {
    const result = await authApi.login(form.value.employee_no, form.value.password)
    auth.setSession(result.token, result.user as unknown as AuthUser)
    ElMessage.success(`欢迎回来，${result.user.name}`)
    router.push((route.query.redirect as string) || '/')
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : '登录失败，请检查后端服务和账号密码')
  } finally {
    loading.value = false
  }
}

function onWecomLogin(): void {
  ElMessage.info('企业微信扫码登录将在联调阶段接入（一期为接口骨架）')
}
</script>

<style scoped>
.login-page {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 55%, #0e7490 100%);
}

.login-card {
  width: 420px;
  background: #fff;
  border-radius: 16px;
  padding: 40px 36px 28px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
}

.brand {
  text-align: center;
  margin-bottom: 24px;
}

.brand-mark {
  width: 56px;
  height: 56px;
  margin: 0 auto 12px;
  border-radius: 14px;
  background: linear-gradient(135deg, #1677ff, #06b6d4);
  color: #fff;
  font-size: 30px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.brand h2 {
  font-size: 22px;
  color: #1f2937;
}

.brand p {
  margin-top: 6px;
  font-size: 13px;
  color: #6b7280;
}

.demo-tip {
  margin-bottom: 16px;
}

.login-btn {
  width: 100%;
  margin-top: 4px;
}

.divider-text {
  font-size: 12px;
  color: #9ca3af;
}

.wecom-btn {
  width: 100%;
}

.tips {
  margin-top: 16px;
  font-size: 12px;
  color: #9ca3af;
  text-align: center;
  line-height: 1.8;
}
</style>
