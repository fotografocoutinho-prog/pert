/** Minimal OpenAPI 3 description of the Phase 1 surface. */
export const openApiDocument = {
  openapi: '3.0.3',
  info: {
    title: 'Digital Signage API',
    version: '0.1.0',
    description: 'REST + WebSocket API for the digital signage platform.',
  },
  servers: [{ url: '/api' }],
  components: {
    securitySchemes: {
      bearerAuth: { type: 'http', scheme: 'bearer', bearerFormat: 'JWT' },
    },
  },
  security: [{ bearerAuth: [] }],
  paths: {
    '/auth/login': {
      post: {
        tags: ['auth'],
        summary: 'Authenticate and receive tokens',
        security: [],
        requestBody: {
          required: true,
          content: {
            'application/json': {
              schema: {
                type: 'object',
                required: ['email', 'password'],
                properties: {
                  email: { type: 'string', format: 'email' },
                  password: { type: 'string' },
                },
              },
            },
          },
        },
        responses: { '200': { description: 'Login response with user and tokens' } },
      },
    },
    '/auth/refresh': {
      post: { tags: ['auth'], summary: 'Rotate refresh token', security: [], responses: { '200': { description: 'New token pair' } } },
    },
    '/auth/me': {
      get: { tags: ['auth'], summary: 'Current user', responses: { '200': { description: 'User' } } },
    },
    '/dashboard/stats': {
      get: { tags: ['dashboard'], summary: 'Aggregate stats', responses: { '200': { description: 'Stats' } } },
    },
    '/monitors': {
      get: { tags: ['monitors'], summary: 'List monitors', responses: { '200': { description: 'Monitor list' } } },
      post: { tags: ['monitors'], summary: 'Create monitor', responses: { '201': { description: 'Created' } } },
    },
    '/monitors/{id}': {
      get: { tags: ['monitors'], summary: 'Get monitor', responses: { '200': { description: 'Monitor' } } },
      patch: { tags: ['monitors'], summary: 'Update monitor', responses: { '200': { description: 'Updated' } } },
      delete: { tags: ['monitors'], summary: 'Delete monitor', responses: { '204': { description: 'Deleted' } } },
    },
    '/monitors/{id}/command': {
      post: { tags: ['monitors'], summary: 'Send remote command', responses: { '202': { description: 'Dispatched' } } },
    },
    '/contents': {
      get: { tags: ['contents'], summary: 'List content', responses: { '200': { description: 'Content list' } } },
      post: { tags: ['contents'], summary: 'Upload content (multipart, field "file")', responses: { '201': { description: 'Created' } } },
    },
    '/playlists': {
      get: { tags: ['playlists'], summary: 'List playlists', responses: { '200': { description: 'Playlist list' } } },
      post: { tags: ['playlists'], summary: 'Create playlist', responses: { '201': { description: 'Created' } } },
    },
    '/playlists/{id}/items': {
      put: { tags: ['playlists'], summary: 'Replace ordered items', responses: { '200': { description: 'Updated' } } },
    },
    '/layouts': {
      get: { tags: ['layouts'], summary: 'List layouts', responses: { '200': { description: 'Layout list' } } },
      post: { tags: ['layouts'], summary: 'Create layout (optionally from a preset)', responses: { '201': { description: 'Created' } } },
    },
    '/layouts/{id}': {
      get: { tags: ['layouts'], summary: 'Get layout', responses: { '200': { description: 'Layout' } } },
      patch: { tags: ['layouts'], summary: 'Update layout / zones', responses: { '200': { description: 'Updated' } } },
      delete: { tags: ['layouts'], summary: 'Delete layout', responses: { '204': { description: 'Deleted' } } },
    },
    '/schedules': {
      get: { tags: ['schedules'], summary: 'List schedules', responses: { '200': { description: 'Schedule list' } } },
      post: { tags: ['schedules'], summary: 'Create schedule', responses: { '201': { description: 'Created' } } },
    },
    '/schedules/{id}': {
      patch: { tags: ['schedules'], summary: 'Update schedule', responses: { '200': { description: 'Updated' } } },
      delete: { tags: ['schedules'], summary: 'Delete schedule', responses: { '204': { description: 'Deleted' } } },
    },
    '/player/{monitorId}/state': {
      get: {
        tags: ['player'],
        summary: 'Resolved render state (layout + zones + scheduled playlist)',
        responses: { '200': { description: 'PlayerState' } },
      },
    },
    '/contents/{id}/thumbnail': {
      get: { tags: ['contents'], summary: 'Content thumbnail (webp)', responses: { '200': { description: 'Thumbnail' } } },
    },
    '/tenants/me': {
      get: { tags: ['tenants'], summary: 'Current tenant', responses: { '200': { description: 'Tenant' } } },
    },
    '/tenants/license': {
      get: { tags: ['tenants'], summary: 'License + screen usage', responses: { '200': { description: 'License' } } },
    },
    '/tenants': {
      post: { tags: ['tenants'], summary: 'Provision a new tenant + admin', responses: { '201': { description: 'Created' } } },
    },
    '/stats/play': {
      get: { tags: ['stats'], summary: 'Proof-of-play aggregation', responses: { '200': { description: 'PlayStats' } } },
    },
  },
} as const;
