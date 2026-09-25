<script setup lang="ts">
// Renders a ```mermaid fence in the browser (M5-05). Mermaid is loaded on first use and follows the
// site's light or dark appearance; the source stays readable if rendering fails.
import { useData } from 'vitepress'
import { onMounted, ref, watch } from 'vue'

const props = defineProps<{ code: string }>()
const { isDark } = useData()
const svg = ref('')
const failed = ref(false)
let counter = 0

async function render() {
  const { default: mermaid } = await import('mermaid')
  mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: isDark.value ? 'dark' : 'default' })
  try {
    const id = `mermaid-${Math.random().toString(36).slice(2)}-${counter++}`
    svg.value = (await mermaid.render(id, decodeURIComponent(props.code))).svg
    failed.value = false
  } catch {
    failed.value = true
  }
}

onMounted(render)
watch(isDark, render)
</script>

<template>
  <div class="mermaid-diagram">
    <pre v-if="failed"><code>{{ decodeURIComponent(code) }}</code></pre>
    <div v-else v-html="svg" />
  </div>
</template>
