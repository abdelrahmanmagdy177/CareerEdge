# CareerEdge — Project Plan

Interview simulation and CV review marketplace. Candidates book timed chat or voice
sessions with freelance HR or technical recruiters, pay up front (money is held until the
session is done), and get structured feedback.

The API contract is in [`openapi.yaml`](./openapi.yaml). The mobile and web apps are built
against it, so **the contract is the source of truth**. If the code and the contract
disagree, the code is wrong.

---

## 1. Scope

| Phase | Name | Contents |
|---|---|---|
| **1: Core MVP** | Foundation | Auth and roles, profiles, CV upload, recruiter discovery, availability, booking, payment hold, live chat, voice join token, session lifecycle, reviews, admin approval of recruiters |
| **2** | Scorecard & Feedback | Scorecard templates per specialization, recruiter scoring after the session, PDF report |
| **3** | Question Bank | In-session suggested questions filtered by specialization, role and seniority |
| **4** | Salary Negotiation | Candidate submits an offer, recruiter returns a fair-range analysis and a negotiation script |
| **5** | Session Archive | Recording opt-in, transcript, summary and tips the candidate can revisit |

Phase 1 is built first and has to be stable and tested. Phases 2–5 are separate modules.
Each one adds its own tables, endpoints and tests and **does not change Phase 1 behaviour**.
The contract already defines their endpoints, marked `x-phase`, so the app team can plan
the screens now.

Out of scope for now: video, group sessions, subscriptions, recruiter payouts automation
(an admin marks payouts as paid manually), multi-currency (EGP only, but the money type
carries a currency).

---

## 2. Architecture

**Modular monolith**: one deployable unit, with a hard boundary around each module.

```
src/
  CareerEdge.Api/                 # Host: Program.cs, endpoints wiring, middleware, SignalR hub
  CareerEdge.SharedKernel/        # Result/Error types, Money, base entity, clock abstraction
  Modules/
    Identity/                     # users, roles, JWT, refresh tokens
    Profiles/                     # candidate & recruiter profiles, specializations, CVs
    Scheduling/                   # offerings, availability slots, sessions, state machine
    Messaging/                    # chat messages, SignalR
    Payments/                     # payment holds, gateway abstraction, webhooks
    Reviews/
    Admin/
    Scorecards/        (phase 2)
    QuestionBank/      (phase 3)
    Salary/            (phase 4)
    Archive/           (phase 5)
tests/
  CareerEdge.UnitTests/
  CareerEdge.IntegrationTests/    # Testcontainers PostgreSQL, WebApplicationFactory
docker-compose.yml                # postgres, minio, api
```

Rules:
- Each module owns its tables, under its own PostgreSQL schema (`identity`, `scheduling`, …).
  Modules never join across schemas in code. They call each other through a small public
  interface (e.g. `ISessionQueries`) or through in-process domain events.
- Folders inside a module are organised by feature (`Features/BookSession/…`), each with
  its request, validator, handler and endpoint.

### Stack

| Concern | Choice | Why |
|---|---|---|
| Runtime | .NET 9, ASP.NET Core Minimal APIs | |
| DB | PostgreSQL 16, EF Core 9 + Npgsql | `jsonb` for scorecard criteria and salary breakdowns |
| Auth | ASP.NET Identity + JWT access (15 min) + rotating refresh tokens (30 days) | |
| Validation | FluentValidation | |
| Realtime chat | SignalR (`/hubs/sessions`) | Built in. Mobile clients exist for Kotlin, Swift and JS |
| Voice | LiveKit (self-hosted or cloud) behind an `IVoiceProvider` interface | We never build WebRTC ourselves. The API only issues room tokens |
| Background jobs | Hangfire (PostgreSQL storage) | Slot hold expiry, auto-complete, payment release |
| File storage | S3-compatible (MinIO locally) behind `IFileStorage` | Pre-signed download URLs |
| Payments | `IPaymentGateway` with `PaymobGateway` and `FakeGateway` (dev/tests) | Tests never call a real gateway |
| PDF | QuestPDF | Phase 2 |
| Transcription | `ITranscriptionService` (Whisper API) | Phase 5 |
| Logging | Serilog, structured, with a correlation id per request | |
| API docs | `openapi.yaml` (hand-maintained contract) + Scalar UI | |

---

## 3. Domain Model (Phase 1)

```
User (id, email, passwordHash, role[candidate|recruiter|admin], fullName, phone?, locale, createdAt)
 ├─ CandidateProfile (userId, headline, yearsOfExperience, targetRole, specializationId)
 ├─ RecruiterProfile (userId, bio, yearsOfExperience, linkedinUrl, verificationStatus[pending|approved|rejected],
 │                    ratingAvg, ratingCount, cancellationCount, timezone)
 │    ├─ RecruiterSpecialization (recruiterId, specializationId)
 │    └─ ServiceOffering (id, recruiterId, sessionType[mock_interview|cv_review|salary_consultation],
 │                        mode[chat|voice], durationMinutes[30|45|60], priceMinor, currency, isActive)
 └─ Cv (id, candidateId, fileName, contentType, sizeBytes, storageKey, uploadedAt, isDeleted)

Specialization (id, slug, nameEn, nameAr, parentId?)      -- e.g. software > backend

AvailabilitySlot (id, recruiterId, startsAt, endsAt, status[open|held|booked])

Session (id, candidateId, recruiterId, offeringId, slotId, cvId?, type, mode[chat|voice],
         scheduledStart, scheduledEnd, actualStart?, actualEnd?, status, priceMinor, currency,
         candidateNotes?, cancelledBy?, cancelReason?, holdExpiresAt?, createdAt)

SessionAttendance (sessionId, userId, firstJoinedAt)      -- drives no-show decisions

Message (id, sessionId, senderId, clientMessageId, body, sentAt)   -- unique(sessionId, clientMessageId)

Payment (id, sessionId, amountMinor, currency, status, gatewayRef, refundedMinor, heldAt?, releasedAt?, refundedAt?)

Review (id, sessionId, candidateId, recruiterId, rating 1-5, comment?, createdAt)  -- one per session

Dispute (id, sessionId, openedBy, reason, status[open|resolved_refund|resolved_release], resolutionNote?, createdAt)
```

All timestamps are `timestamptz` and stored as UTC. Money is stored as `bigint` minor units
(piasters) plus a `currency` char(3). Never use floating point for money.

---

## 4. Session Lifecycle

```
                 create
                   │
            ┌──────▼────────┐  hold expires (15 min) / candidate cancels
            │pending_payment├─────────────────────────────► cancelled
            └──────┬────────┘
     payment webhook: held
            ┌──────▼───────┐  cancel (refund per policy)
            │  confirmed   ├──────────────────────────────► cancelled
            └──────┬───────┘
  first participant joins (window: start-10min … end)
            ┌──────▼───────┐
            │ in_progress  │
            └──────┬───────┘
  recruiter ends, or scheduledEnd + 10 min (job)
            ┌──────▼───────┐  candidate opens dispute ≤ 24h
            │  completed   ├──────────────────────────────► disputed ──► (admin) completed | refunded
            └──────────────┘
  confirmed and nobody joined by scheduledEnd ──► no_show (job decides refund; see rules)
```

The payment follows the session:

| Session event | Payment status |
|---|---|
| created | — (no payment row) |
| checkout started | `pending` |
| gateway webhook success | `held` |
| completed + 24h with no dispute | `released` (job) |
| cancelled / refunded | `refunded` or `partially_refunded` |

### Business rules (enforced server-side, covered by tests)

1. Only `approved` recruiters show up in search and can be booked.
2. A slot moves `open → held` on session create, and `held → booked` when the payment is
   held. A hold lasts 15 minutes; then the session is cancelled and the slot reopens.
3. A candidate cannot have two non-cancelled sessions that overlap in time.
4. Slots must be in the future, at least 30 min long, and must not overlap the recruiter's
   other slots. The session starts at the slot start and lasts the offering duration,
   which must fit inside the slot.
5. Cancellation:
   - candidate, more than 24h before start → 100% refund
   - candidate, 24h or less before start → 50% refund
   - recruiter, any time before start → 100% refund, and it increments the recruiter's
     `cancellationCount`
   - nobody can cancel once the session is `in_progress`
6. Join window: from `scheduledStart - 10 min` to `scheduledEnd`. Outside it the API
   returns `409 SESSION_NOT_JOINABLE`.
7. No-show (status `no_show`): if only the candidate joined, or nobody joined, the
   candidate gets a full refund. If the recruiter joined and the candidate did not, the
   session goes to `completed` instead and the payment is released as normal.
8. Chat: participants can send messages from `confirmed` until `completed`. After that
   the chat is read-only, forever. Max 4000 characters per message. The same
   `clientMessageId` sent twice stores one message (idempotent retries from mobile).
9. Reviews: candidate only, only on `completed`, one per session, within 14 days.
10. A CV attached to a session stays readable by that recruiter until 7 days after the
    session ends, even if the candidate deletes it from their list (soft delete).
11. A recruiter can read only the CV attached to their own session. A candidate can
    read only their own CVs.

---

## 5. API Conventions (summary; details in openapi.yaml)

- Base path `/api/v1`. Breaking changes mean `/api/v2`.
- Auth: `Authorization: Bearer <access token>`. Refresh with `POST /auth/refresh`.
- JSON in `camelCase`. Enums are `snake_case` strings. Timestamps are ISO-8601 UTC (`2026-11-10T18:00:00Z`).
- Money is always `{ "amountMinor": 45000, "currency": "EGP" }`.
- Lists use `?page=1&pageSize=20` (max 100) and return `{ items, page, pageSize, totalCount }`.
  Chat history uses a cursor (`?before=<messageId>&limit=50`).
- Errors use RFC 7807 `application/problem+json` with a stable `code` and, on 400, an
  `errors` map per field. The app switches on `code`, never on `title` or `detail`.
- `Idempotency-Key` header (UUID) is **required** on `POST /sessions` and
  `POST /sessions/{id}/checkout`. Replaying the same key returns the original response.
- `Accept-Language: ar|en` picks the language of specialization names and error `detail`.

### Realtime: SignalR hub `/hubs/sessions`

Connect with `?access_token=<jwt>`.

| Direction | Name | Payload |
|---|---|---|
| client → server | `JoinSession` | `sessionId` |
| client → server | `LeaveSession` | `sessionId` |
| client → server | `SendMessage` | `{ sessionId, clientMessageId, body }` |
| client → server | `Typing` | `sessionId` |
| server → client | `MessageReceived` | `Message` (same schema as REST) |
| server → client | `UserTyping` | `{ sessionId, userId }` |
| server → client | `SessionStatusChanged` | `{ sessionId, status, at }` |
| server → client | `SessionEndingSoon` | `{ sessionId, endsAt }` (5 min before the end) |
| server → client | `ScorecardSubmitted` | `{ sessionId, scorecardId }` (phase 2) |

`POST /sessions/{id}/messages` does the same thing as `SendMessage`, as a REST fallback.
Both go through the same handler. Hub errors are sent as a `HubException` whose message
is one of the error `code`s.

---

## 6. Milestones (Phase 1)

| # | Deliverable | Done when |
|---|---|---|
| M0 | Repo skeleton, docker-compose, CI (build + test), health check | `docker compose up` gives a working `/health` |
| M1 | Identity: register, login, refresh, logout, me | Integration tests cover token rotation and reuse detection |
| M2 | Profiles, specializations (seeded), CV upload/download | File type and size limits tested (PDF/DOCX, ≤ 5 MB) |
| M3 | Recruiter offerings, availability, search | Overlap rules tested |
| M4 | Session booking + FakeGateway checkout + webhook + hold expiry job | Full booking flow test, from create to confirmed |
| M5 | Chat (SignalR + REST), join token, lifecycle jobs | State machine has a unit test per transition |
| M6 | Cancellation and refunds, no-show, release job, disputes, reviews | Every rule in §4 has a test |
| M7 | Admin: approve/reject recruiters, resolve disputes | |
| M8 | Contract conformance test: each endpoint in openapi.yaml exists with matching status codes | CI fails on drift |

---

## 7. Feature Requests (Phases 2–5)

Each one is self-contained and is implemented against the endpoints already in the contract.

### FR-1: Scorecard & PDF report (phase 2)
- Admin manages `ScorecardTemplate`s per specialization: 3–10 criteria, each with a `key`,
  a label in `ar` and `en`, a weight from 1 to 5, and a category from
  `communication | technical | cv_structure | other`.
- After a session is `completed`, the recruiter of that session submits exactly one scorecard
  within 72h: an integer score from 1 to 5 per criterion (every criterion is required),
  optional per-criterion comments, and required `strengths`, `improvements` and
  `overallComment`.
- `overallScore` is the weighted average, rounded to 1 decimal, and is computed on the server.
- The template is snapshotted into the scorecard, so later template edits do not change
  scorecards that were already submitted.
- `GET /sessions/{id}/scorecard/pdf` returns `application/pdf`. It is readable by the
  candidate, the recruiter and admins.
- The `ScorecardSubmitted` hub event is sent to the candidate.

### FR-2: Interactive Question Bank (phase 3)
- Each question has a specialization, a `roleLevel` (`intern | junior | mid | senior | lead`),
  a `category` (`behavioral | technical | situational | hr`), text in `ar`/`en`, an optional
  `idealAnswerHints` (recruiter-only), and `tags`.
- Search with filters, pagination and full-text search (PostgreSQL `tsvector`).
- In a session, the recruiter pins questions (`POST /sessions/{id}/questions`). Pinned
  questions appear for both participants in order. `idealAnswerHints` is never returned
  to candidates.
- Approved recruiters can propose questions. Admins approve them.

### FR-3: Salary Negotiation Calculator (phase 4)
- The candidate creates a consultation with the offer details: base salary, period
  (monthly/yearly), currency, benefits list, role, specialization, years of experience,
  city, and optional competing offers.
- The server computes `totalCompensationYearly`.
- The recruiter of the linked `salary_consultation` session replies with
  `fairRange { min, max }`, a `verdict` (`below_market | fair | above_market`), a
  `counterOfferSuggestion`, and `negotiationScript` (markdown).
- The verdict must agree with the range: `below_market` if the offer's yearly total is under
  `min`, `above_market` if it is over `max`, `fair` otherwise. The server rejects a reply
  where they disagree (`400 VERDICT_RANGE_MISMATCH`).

### FR-4: Session Archive (phase 5)
- Recording happens only if **both** participants consent (`POST /sessions/{id}/recording-consent`).
- After completion, the recruiter writes notes and tips. If there is a recording, the
  transcript is generated asynchronously (`status: processing | ready | failed`).
- `GET /me/archive` lists the candidate's past sessions with their summary, scorecard
  link and tips, and can be filtered by date and type.

---

## 8. Testing Strategy

- **Unit**: state machine transitions, refund calculation, weighted score, overlap checks.
  The clock is injected (`TimeProvider`), so tests never depend on the real time.
- **Integration**: real PostgreSQL through Testcontainers, `WebApplicationFactory`,
  FakeGateway and fake storage. One test file per feature folder.
- **Contract**: a test that loads `openapi.yaml` and checks that every path and method is
  mapped, and that responses validate against the schemas.
- CI runs on every push. The `main` branch must stay green.

## 9. Security Checklist

- Every resource lookup filters by ownership or participation. Unauthorised access returns
  `404`, not `403`, so the API does not reveal that the resource exists.
- Refresh tokens are stored hashed and rotated on each use. If an old token is reused, the
  whole token family is revoked.
- Rate limits: auth endpoints get 10 requests/min per IP. Message sending gets 30 messages/min per user.
- Payment webhooks are verified with the gateway HMAC before any processing, and are
  idempotent on `gatewayRef`.
- Uploads: the content type is checked by magic bytes, not just by extension. Files are
  stored under random keys and only served through pre-signed URLs that expire in 5 minutes.
- No PII in logs (emails and phones are masked).
