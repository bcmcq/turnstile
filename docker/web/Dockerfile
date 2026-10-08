# Dev server image. Production build (vite build → Caddy) is a README note for now.
FROM node:22-alpine
WORKDIR /app
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --no-fund --no-audit
COPY apps/web/ ./
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]
