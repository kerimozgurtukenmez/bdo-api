// Stand-ins for API endpoints that do not exist yet (see VITE_API_MOCKS in
// src/api.js). Add a module here per endpoint; its header comment documents
// the response the API has to return. When the endpoint is built, remove the
// mock and its name from VITE_API_MOCKS.
// Every mock response carries `mock: true` so pages can say it is sample data.
//
// Example:
//   import { profitRanking } from './ranking.js'
//   const MOCKS = { ranking: profitRanking }
//   // ranking(params, request) — request() calls endpoints that already exist

import { ApiError } from '../api.js'

const MOCKS = {}

const LATENCY = 200  // ms, so loading states are visible

export async function mock(endpoint, params, request) {
  await new Promise((resolve) => setTimeout(resolve, LATENCY))

  if (!MOCKS[endpoint]) throw new ApiError(`No mock for ${endpoint}.php`, 501)
  return MOCKS[endpoint](params, request)
}
