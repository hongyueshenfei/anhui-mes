// esbuild 验证脚本专用 stub：node 环境无 element-plus，仅需 ElMessage 占位
export const ElMessage = {
  error: () => {},
  warning: () => {},
  success: () => {},
  info: () => {},
}
