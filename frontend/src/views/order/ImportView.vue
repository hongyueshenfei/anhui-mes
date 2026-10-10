<template>
  <el-dialog v-model="visible" title="Excel 导入订单" width="min(560px, calc(100vw - 24px))" append-to-body destroy-on-close>
    <el-alert type="info" :closable="false" class="mb-12">
      支持 CSV / XLSX。首行为表头：产品编码、数量、交期、客户、优先级。
      编码不匹配行将明确报错（行号+编码+原因），合法行照常导入。
    </el-alert>

    <el-upload
      drag
      accept=".csv,.xlsx,.xls,.ods,.txt"
      :auto-upload="false"
      :limit="1"
      :disabled="importing"
      :on-change="onFileChange"
      :on-remove="onFileRemove"
    >
      <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
      <div class="el-upload__text">拖入文件或<em>点击选择</em></div>
      <template #tip>
        <div class="el-upload__tip">
          可下载 <el-link type="primary" :href="orderApi.templateUrl()" target="_blank">导入模板（CSV）</el-link>
        </div>
      </template>
    </el-upload>

    <div v-if="result" class="result-panel" :class="{ error: !result.success }">
      <div class="result-title">
        <el-tag :type="result.success ? 'success' : 'danger'" size="small">
          {{ result.success ? `导入成功 ${result.imported_count} 行` : '无可导入的合法行' }}
        </el-tag>
        <span v-if="result.skipped_count > 0" class="skip-tag">跳过 {{ result.skipped_count }} 行</span>
      </div>
      <template v-if="result.error_rows?.length">
        <div class="err-title">错误行（{{ result.error_rows.length }}）：</div>
        <div v-for="(e, i) in result.error_rows" :key="i" class="err-row">
          第 {{ e.row }} 行 · 编码 {{ e.product_code || '-' }}：{{ e.error }}
        </div>
      </template>
    </div>

    <el-alert v-if="importError" type="error" show-icon :closable="false" class="import-error" :title="importError" />

    <template #footer>
      <el-button @click="close">关闭</el-button>
      <el-button type="primary" :loading="importing" :disabled="!file" @click="onImport">
        <el-icon><Upload /></el-icon>开始导入
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Upload, UploadFilled } from '@element-plus/icons-vue'
import type { UploadFile } from 'element-plus'
import { orderApi } from '@/api/order'
import type { ImportResult } from '@/types/schedule'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: boolean): void; (e: 'imported'): void }>()

const visible = ref(false)
const file = ref<File | null>(null)
const importing = ref(false)
const result = ref<ImportResult | null>(null)
const importError = ref('')

watch(
  () => props.modelValue,
  (v) => {
    visible.value = v
    if (v) {
      file.value = null
      result.value = null
      importError.value = ''
    }
  },
)
watch(visible, (v) => emit('update:modelValue', v))

function onFileChange(uploadFile: UploadFile): void {
  file.value = uploadFile.raw ?? null
  result.value = null
  importError.value = ''
}

function onFileRemove(): void {
  file.value = null
  result.value = null
  importError.value = ''
}

function close(): void {
  visible.value = false
}

async function onImport(): Promise<void> {
  if (!file.value) return
  importing.value = true
  importError.value = ''
  try {
    const res = await orderApi.importExcel(file.value)
    result.value = res
    if (res.success) {
      ElMessage.success(res.skipped_count > 0 ? `导入成功 ${res.imported_count} 行，跳过 ${res.skipped_count} 行` : `导入成功 ${res.imported_count} 行`)
      emit('imported')
    } else {
      ElMessage.error('无可导入的合法行')
    }
  } catch (error: any) {
    importError.value = error?.response?.data?.message ?? '导入请求失败；请确认文件内容并检查服务连接后重试。'
  } finally {
    importing.value = false
  }
}
</script>

<style scoped>
.mb-12 {
  margin-bottom: 12px;
}
.import-error {
  margin-top: 12px;
}
.result-panel {
  margin-top: 14px;
  border: 1px solid #d1fae5;
  background: #f0fdf4;
  border-radius: 6px;
  padding: 10px 12px;
}
.result-panel.error {
  border-color: #fecaca;
  background: #fef2f2;
}
.result-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.skip-tag {
  font-size: 12px;
  color: #d97706;
}
.err-title {
  font-weight: 600;
  margin: 6px 0 4px;
  font-size: 13px;
}
.err-row {
  font-size: 12px;
  color: #b91c1c;
  line-height: 1.7;
}
</style>
