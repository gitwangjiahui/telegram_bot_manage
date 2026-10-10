# syntax=docker/dockerfile:1

# ---- builder ----
FROM golang:1.23 AS builder

WORKDIR /src

# Cache dependencies first.
COPY go.mod go.sum ./
RUN go mod download

# Copy sources (font is embedded from the module, no assets dir needed).
COPY . .

# Static build for linux/amd64.
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
    go build -trimpath -ldflags="-s -w" -o /out/botd ./cmd/botd

# ---- runtime ----
FROM gcr.io/distroless/static-debian12:nonroot

ENV TZ=Asia/Shanghai

COPY --from=builder /out/botd /botd

# Host network publishes the port; EXPOSE is documentary only.
EXPOSE 19090

USER nonroot:nonroot
ENTRYPOINT ["/botd"]
