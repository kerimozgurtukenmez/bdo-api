import { onBeforeUnmount, toValue, watchEffect } from 'vue'
import { useRouter } from 'vue-router'

// Page title and meta description for pages whose text depends on data
// (e.g. an item name). Static titles come from the route's meta.title; this
// re-applies after every navigation so a URL fix-up does not overwrite it.

const DEFAULT_DESCRIPTION = document.querySelector('meta[name="description"]')?.getAttribute('content') ?? ''

export function usePageMeta(title, description) {
  const meta = document.querySelector('meta[name="description"]')

  function apply() {
    const titleText = toValue(title)
    if (titleText) document.title = `${titleText} · BDO Craft`
    meta?.setAttribute('content', toValue(description) || DEFAULT_DESCRIPTION)
  }

  watchEffect(apply)
  const removeHook = useRouter().afterEach(apply)  // runs after the router's own title update

  onBeforeUnmount(() => {
    removeHook()
    meta?.setAttribute('content', DEFAULT_DESCRIPTION)
  })
}
