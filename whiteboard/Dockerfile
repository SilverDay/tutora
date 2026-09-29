FROM node:22-slim
WORKDIR /srv/whiteboard
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY src ./src
USER node
ENV NODE_ENV=production
ENTRYPOINT ["node", "src/main.js"]
