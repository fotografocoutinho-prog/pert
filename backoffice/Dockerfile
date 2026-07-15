# ---- build stage ----
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json tsconfig.base.json ./
COPY shared/package.json ./shared/
COPY backoffice/package.json ./backoffice/
RUN npm ci --workspace @signage/shared --workspace @signage/backoffice --include-workspace-root
COPY shared ./shared
COPY backoffice ./backoffice
RUN npm run build --workspace @signage/shared \
 && npm run build --workspace @signage/backoffice

# ---- runtime stage (static files via nginx) ----
FROM nginx:1.27-alpine AS runtime
COPY backoffice/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/backoffice/dist /usr/share/nginx/html
EXPOSE 80
