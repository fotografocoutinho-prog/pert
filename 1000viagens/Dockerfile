# 1000viagens — imagem mínima, sem dependências externas
FROM node:22-alpine

WORKDIR /app
COPY package.json ./
COPY server ./server
COPY public ./public
COPY scripts ./scripts

# Os dados (pedidos, definições, uploads) vivem num volume, fora da imagem
VOLUME ["/app/data"]
ENV PORT=3000 DATA_DIR=/app/data NODE_ENV=production
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=4s --start-period=5s \
  CMD wget -qO- http://127.0.0.1:3000/api/public/config > /dev/null || exit 1

CMD ["node", "server/index.js"]
