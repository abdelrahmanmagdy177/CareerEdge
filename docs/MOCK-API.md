# Mock API for the app team

You can build the whole app against a mock server now, while the backend is still being
written. The mock is generated from [`openapi.yaml`](./openapi.yaml), so its responses have
exactly the shape the real backend will return.

## Run it

Pick one:

```bash
# with Docker
docker compose up mock

# with Node.js 18+
npx @stoplight/prism-cli@5.12.0 mock -h 0.0.0.0 -p 4010 docs/openapi.yaml
```

| Client | Base URL |
|---|---|
| Same machine / iOS simulator | `http://localhost:4010` |
| Android emulator | `http://10.0.2.2:4010` |
| Real phone on the same Wi-Fi | `http://<your-laptop-ip>:4010` |

> ⚠️ **The mock has no `/api/v1` prefix.** It serves `/auth/login` directly. The real backend
> will be `http://<host>:8080/api/v1/auth/login`. Keep the base URL in one config value
> and switch it per environment.

## What it does

- **Every endpoint answers** with realistic data: Ahmed (candidate), Sara (recruiter), a 45-minute
  voice mock interview on 2026-11-20 for 450 EGP.
- **Auth is checked loosely.** Endpoints that need a token return `401` if there is no
  `Authorization: Bearer …` header. Any token value is accepted.
- **Requests are validated.** A body or query that breaks the contract returns an error
  (e.g. `400` on login with a missing email). This is a free way to catch client bugs early.

## Get a specific response with the `Prefer` header

| Header | You get |
|---|---|
| *(none)* | The default success response |
| `Prefer: example=completed` | A named example (list below) |
| `Prefer: code=409` | That status code |
| `Prefer: code=409, example=SLOT_UNAVAILABLE` | A specific error |

**Session states** (`GET /sessions/{id}`), so you can build every screen:
`confirmed` (default) · `pending_payment` · `in_progress` · `completed` · `completed_reviewed` ·
`cancelled` · `disputed` · `chat_cv_review`

**Join** (`POST /sessions/{id}/join`): `voice` (default, includes a LiveKit token) · `chat`

**Errors**

| Status | Examples |
|---|---|
| 401 | `TOKEN_EXPIRED`, `INVALID_CREDENTIALS` |
| 403 | `FORBIDDEN_ROLE`, `RECRUITER_NOT_APPROVED` |
| 404 | `NOT_FOUND` |
| 409 | `SLOT_UNAVAILABLE`, `SLOT_OVERLAP`, `CANDIDATE_TIME_CONFLICT`, `INVALID_SESSION_STATE`, `SESSION_NOT_JOINABLE`, `CHAT_CLOSED`, `ALREADY_REVIEWED`, `EMAIL_TAKEN`, `CV_LIMIT_REACHED`, `OFFERING_EXISTS`, `DISPUTE_ALREADY_RESOLVED` |
| 429 | `RATE_LIMITED` |

Example:

```bash
curl -H "Authorization: Bearer x" \
     -H "Prefer: code=409, example=SESSION_NOT_JOINABLE" \
     -X POST http://localhost:4010/sessions/9a4e6b1c-2d7f-4a3e-b5c8-3c4d5e6f7a03/join
```

In app code, send `Prefer` from a debug-only interceptor. Never ship it in release builds.

## What it does NOT do

- **It keeps no state.** `POST /sessions` does not make the session appear in
  `GET /sessions`, and a cancel does not change anything. Use `Prefer: example=…` to show
  each state.
- **No realtime chat.** The WebSocket server (Laravel Reverb, see [PLAN.md §5](./PLAN.md)) is
  not mocked. Build the chat UI on `GET/POST /sessions/{id}/messages` first, then add the socket.
- **No real payment page or voice room.** `paymentUrl` and the LiveKit token are placeholders.
- **Phase 2–5 endpoints** (scorecards, questions, salary, archive) also respond, but the real
  backend will return `404` for them until each phase ships.

## When the contract changes

The mock reloads by itself when `openapi.yaml` changes. Pull the latest `main` and restart
it if needed.
