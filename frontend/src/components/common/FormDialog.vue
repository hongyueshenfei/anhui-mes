<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="640px"
    :close-on-click-modal="false"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
    @open="onOpen"
  >
    <el-form ref="formRef" :model="formModel" :rules="rules" label-width="110px">
      <el-form-item v-for="field in visibleFields" :key="field.prop" :label="field.label" :prop="field.prop">
        <!-- 输入框 -->
        <el-input
          v-if="field.type === 'input'"
          v-model="formModel[field.prop]"
          :placeholder="field.placeholder ?? `请输入${field.label}`"
          :disabled="field.disabled"
        />
        <!-- 数字 -->
        <el-input-number
          v-else-if="field.type === 'number'"
          v-model="formModel[field.prop]"
          :min="field.min ?? 0"
          :max="field.max"
          :precision="field.precision"
          :disabled="field.disabled"
          :style="{ width: '100%' }"
        />
        <!-- 下拉 -->
        <el-select
          v-else-if="field.type === 'select'"
          v-model="formModel[field.prop]"
          :placeholder="field.placeholder ?? `请选择${field.label}`"
          :clearable="field.clearable"
          :multiple="field.multiple"
          :filterable="field.filterable"
          :disabled="field.disabled"
          :style="{ width: '100%' }"
        >
          <template v-for="opt in field.options" :key="'options' in opt ? opt.label : opt.value">
            <el-option-group v-if="'options' in opt" :label="opt.label">
              <el-option
                v-for="child in opt.options"
                :key="child.value"
                :label="child.label"
                :value="child.value"
              />
            </el-option-group>
            <el-option v-else :label="opt.label" :value="opt.value" />
          </template>
        </el-select>
        <!-- 开关 -->
        <el-switch
          v-else-if="field.type === 'switch'"
          v-model="formModel[field.prop]"
          :active-value="1"
          :inactive-value="0"
          :disabled="field.disabled"
        />
        <!-- 日期 -->
        <el-date-picker
          v-else-if="field.type === 'date'"
          v-model="formModel[field.prop]"
          type="date"
          value-format="YYYY-MM-DD"
          :disabled="field.disabled"
          :style="{ width: '100%' }"
        />
        <!-- 文本域 -->
        <el-input
          v-else-if="field.type === 'textarea'"
          v-model="formModel[field.prop]"
          type="textarea"
          :rows="3"
          :disabled="field.disabled"
        />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="emit('update:modelValue', false)">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSubmit">保存</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ElMessage, type FormInstance, type FormItemRule, type FormRules } from 'element-plus'

/**
 * 通用表单弹窗：基于字段配置渲染 Element Plus 表单
 */
export interface FormField {
  prop: string
  label: string
  type: 'input' | 'number' | 'select' | 'switch' | 'date' | 'textarea'
  placeholder?: string
  /** 下拉选项：普通选项，或带分组头（如按产品分组的工艺路线） */
  options?: Array<
    | { label: string; value: string | number | boolean }
    | { label: string; options: Array<{ label: string; value: string | number | boolean }> }
  >
  /** 下拉是否可搜索（大量选项时启用） */
  filterable?: boolean
  clearable?: boolean
  multiple?: boolean
  min?: number
  max?: number
  precision?: number
  required?: boolean
  /** 禁用控件（如金蝶同步的基础信息，UI 明确禁用不可编辑） */
  disabled?: boolean
  show?: (model: Record<string, unknown>) => boolean
}

const props = defineProps<{
  modelValue: boolean
  title: string
  fields: FormField[]
  model: Record<string, unknown>
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'submit', model: Record<string, unknown>): void
}>()

const formRef = ref<FormInstance>()
const saving = ref(false)
const formModel = ref<Record<string, unknown>>({})

const visibleFields = computed(() => {
  // 使用 computed 需要导入；这里在 script 顶部补 import
  return props.fields.filter((f) => !f.show || f.show(formModel.value))
})

const rules = computed<FormRules>(() => {
  const result: FormRules = {}
  for (const field of props.fields) {
    if (field.required) {
      const rule: FormItemRule = { required: true, message: `请填写${field.label}`, trigger: 'blur' }
      if (field.type === 'select' && !field.multiple) {
        rule.message = `请选择${field.label}`
      }
      result[field.prop] = [rule]
    }
  }
  return result
})

function onOpen(): void {
  // 深拷贝初始值，避免污染外部 model
  formModel.value = JSON.parse(JSON.stringify(props.model ?? {}))
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    emit('submit', JSON.parse(JSON.stringify(formModel.value)))
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
/* 通用弹窗无需额外样式 */
</style>
