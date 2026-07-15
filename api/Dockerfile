# ---- build stage ----
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json tsconfig.base.json ./
COPY shared/package.json ./shared/
COPY api/package.json ./api/
RUN npm ci --workspace @signage/shared --workspace @signage/api --include-workspace-root
COPY shared ./shared
COPY api ./api
RUN npm run build --workspace @signage/shared \
 && npm run build --workspace @signage/api

# ---- runtime stage ----
FROM node:22-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
COPY shared/package.json ./shared/
COPY api/package.json ./api/
RUN npm ci --omit=dev --workspace @signage/shared --workspace @signage/api --include-workspace-root
COPY --from=build /app/shared/dist ./shared/dist
COPY --from=build /app/api/dist ./api/dist
COPY database ./database
EXPOSE 4000
CMD ["node", "api/dist/index.js"]
