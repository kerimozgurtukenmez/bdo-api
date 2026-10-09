// Stand-ins for API endpoints that do not exist yet (see VITE_API_MOCKS in
// src/api.js). Each file documents the response the API has to return; when
// the endpoint is built, remove its name from VITE_API_MOCKS.
// Every mock response carries `mock: true` so pages can say it is sample data.

import { ApiError } from '../api.js'
import { imperialBoxes } from './imperial.js'
import { masteryTables } from './mastery.js'
import { priceHistory } from './prices.js'

const LATENCY = 200  // ms, so loading states are visible

export async function mock(endpoint, params, request) {
  await new Promise((resolve) => setTimeout(resolve, LATENCY))

  switch (endpoint) {
    case 'prices':
      return priceHistory(params, request)
    case 'mastery':
      return masteryTables()
    case 'imperial':
      return imperialBoxes(params, request)
    default:
      throw new ApiError(`No mock for ${endpoint}.php`, 501)
  }
}
