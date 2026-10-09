<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { compact, number, silver } from '../format.js'

// Single-series line chart of market prices over time. A crosshair snaps to
// the nearest day (pointer or arrow keys); a table view shows every value.

const props = defineProps({
  /** [{ t: unix seconds, price, stock }], oldest first */
  points: { type: Array, required: true },
  /** What the line is, for screen readers ("Beer market price") */
  label: { type: String, required: true },
})

const HEIGHT = 220
const PAD = { top: 12, right: 16, bottom: 28, left: 56 }

const wrap = ref(null)
const width = ref(640)
let observer = null

onMounted(() => {
  width.value = wrap.value.clientWidth || width.value  // no jump on the first frame
  observer = new ResizeObserver(([entry]) => (width.value = entry.contentRect.width))
  observer.observe(wrap.value)
})
onBeforeUnmount(() => observer?.disconnect())

const innerWidth = computed(() => Math.max(1, width.value - PAD.left - PAD.right))
const innerHeight = HEIGHT - PAD.top - PAD.bottom

// Round axis values: steps of 1, 2 or 5 × 10^n
function niceTicks(min, max, count = 4) {
  if (min === max) {
    min = min * 0.9
    max = max * 1.1 || 1
  }
  const raw = (max - min) / count
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= raw)
  const ticks = []
  for (let v = Math.floor(min / step) * step; v <= max + step * 0.001; v += step) ticks.push(v)
  if (ticks[ticks.length - 1] < max) ticks.push(ticks[ticks.length - 1] + step)
  return ticks
}

const prices = computed(() => props.points.map((p) => p.price))
const yTicks = computed(() => niceTicks(Math.min(...prices.value), Math.max(...prices.value)))
const yMin = computed(() => yTicks.value[0])
const yMax = computed(() => yTicks.value[yTicks.value.length - 1])

const x = (i) => PAD.left + (props.points.length === 1 ? innerWidth.value / 2 : (i / (props.points.length - 1)) * innerWidth.value)
const y = (value) => PAD.top + (1 - (value - yMin.value) / (yMax.value - yMin.value)) * innerHeight

const line = computed(() => props.points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.price).toFixed(1)}`).join(''))
const area = computed(() => {
  const n = props.points.length
  const bottom = PAD.top + innerHeight
  return `${line.value}L${x(n - 1).toFixed(1)},${bottom}L${x(0).toFixed(1)},${bottom}Z`
})

const tickLabel = (value) => (yMax.value >= 1_000_000 ? compact(value) : silver(value))

// Points are days at 00:00 UTC: format them in UTC so every time zone sees the same date
const dateFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' })
const longDateFormat = new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
const date = (t) => dateFormat.format(new Date(t * 1000))

// About four date labels, evenly spread
const xTicks = computed(() => {
  const n = props.points.length
  if (n < 2) return n ? [0] : []
  const count = Math.min(4, n)
  return [...new Set(Array.from({ length: count }, (_, k) => Math.round((k * (n - 1)) / (count - 1))))]
})

// ── Hover / keyboard ───────────────────────────────────────────────────
const active = ref(null)

function onPointerMove(event) {
  const rect = event.currentTarget.getBoundingClientRect()
  const position = (event.clientX - rect.left - PAD.left) / innerWidth.value
  active.value = Math.min(props.points.length - 1, Math.max(0, Math.round(position * (props.points.length - 1))))
}

function onKeydown(event) {
  const last = props.points.length - 1
  if (event.key === 'ArrowLeft') active.value = Math.max(0, (active.value ?? last + 1) - 1)
  else if (event.key === 'ArrowRight') active.value = Math.min(last, (active.value ?? -1) + 1)
  else if (event.key === 'Escape') active.value = null
  else return
  event.preventDefault()
}

const activePoint = computed(() => (active.value == null ? null : props.points[active.value]))
const tooltipStyle = computed(() => {
  const left = x(active.value)
  const flip = left > width.value - 160
  return { left: `${left}px`, top: `${y(activePoint.value.price) - 12}px`, transform: `translate(${flip ? 'calc(-100% - 12px)' : '12px'}, -100%)` }
})

const showTable = ref(false)
</script>

<template>
  <div class="chart">
    <div ref="wrap" class="plot">
      <svg
        :width="width"
        :height="HEIGHT"
        role="img"
        :aria-label="`${label}, ${points.length} days. Use the arrow keys to read each day.`"
        tabindex="0"
        @pointermove="onPointerMove"
        @pointerleave="active = null"
        @keydown="onKeydown"
        @blur="active = null"
      >
        <g class="grid">
          <line v-for="tick in yTicks" :key="tick" :x1="PAD.left" :x2="width - PAD.right" :y1="y(tick)" :y2="y(tick)" />
        </g>
        <g class="axis">
          <text v-for="tick in yTicks" :key="tick" :x="PAD.left - 8" :y="y(tick)" text-anchor="end" dominant-baseline="middle">{{ tickLabel(tick) }}</text>
          <text v-for="i in xTicks" :key="i" :x="x(i)" :y="HEIGHT - 8" :text-anchor="i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'">{{ date(points[i].t) }}</text>
        </g>
        <path class="area" :d="area" />
        <path class="line" :d="line" />
        <g v-if="activePoint">
          <line class="crosshair" :x1="x(active)" :x2="x(active)" :y1="PAD.top" :y2="PAD.top + innerHeight" />
          <circle class="marker" :cx="x(active)" :cy="y(activePoint.price)" r="4" />
        </g>
      </svg>

      <div v-if="activePoint" class="tooltip" :style="tooltipStyle" aria-live="polite">
        <div class="tooltip-value num"><span class="key" aria-hidden="true"></span>{{ silver(activePoint.price) }}</div>
        <div class="tooltip-meta">{{ longDateFormat.format(new Date(activePoint.t * 1000)) }}</div>
        <div class="tooltip-meta num">{{ number(activePoint.stock) }} in stock</div>
      </div>
    </div>

    <button class="btn btn-ghost btn-sm table-toggle" type="button" :aria-expanded="showTable" @click="showTable = !showTable">
      {{ showTable ? 'Hide table' : 'Show as table' }}
    </button>
    <div v-if="showTable" class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">Date</th><th scope="col" class="right">Price</th><th scope="col" class="right">Stock</th></tr>
        </thead>
        <tbody>
          <tr v-for="point in [...points].reverse()" :key="point.t">
            <td>{{ longDateFormat.format(new Date(point.t * 1000)) }}</td>
            <td class="right num">{{ silver(point.price) }}</td>
            <td class="right num">{{ number(point.stock) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.plot {
  position: relative;
  width: 100%;
}

svg {
  display: block;
  overflow: visible;
  touch-action: pan-y;
}

svg:focus-visible {
  outline-offset: 4px;
}

.grid line {
  stroke: var(--chart-grid);
  stroke-width: 1;
}

.axis text {
  fill: var(--text-faint);
  font-size: 11px;
  font-variant-numeric: tabular-nums;
}

.area {
  fill: var(--chart-1-wash);
}

.line {
  fill: none;
  stroke: var(--chart-1);
  stroke-width: 2;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.crosshair {
  stroke: var(--border-strong);
  stroke-width: 1;
}

.marker {
  fill: var(--chart-1);
  stroke: var(--surface);
  stroke-width: 2;
}

.tooltip {
  position: absolute;
  z-index: 5;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  box-shadow: var(--shadow-float);
  pointer-events: none;
  white-space: nowrap;
}

.tooltip-value {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-sm);
  font-weight: 600;
}

.key {
  width: 12px;
  height: 2px;
  border-radius: 1px;
  background: var(--chart-1);
}

.tooltip-meta {
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.table-toggle {
  margin-top: var(--space-2);
}

.table-wrap {
  max-height: 280px;
  margin-top: var(--space-2);
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-sm);
}

th,
td {
  padding: var(--space-2) var(--space-3);
  text-align: left;
}

th {
  position: sticky;
  top: 0;
  background: var(--surface);
  color: var(--text-muted);
  font-size: var(--text-xs);
  font-weight: 500;
}

td {
  border-top: 1px solid var(--border);
}

.right {
  text-align: right;
}
</style>
