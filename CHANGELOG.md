# Changelog

## 2.2.0 — 2026-09-25

### Ajouté
- **`completeDemand($reference, $message = '')`** — marque une demande e-Service comme terminée (`state_data.phase = done`). Idempotent si déjà terminée. Message citoyen optionnel (même canal que `sendMessage`).
- Endpoint : `POST /partner/runs/by-reference/complete/`

### Documentation
- README / table des méthodes étendus (5 méthodes SDK simplifiées).

## 2.1.2

- Corrections et stabilisation sendMessage / sendDocument (by-reference APIM).

## 2.1.x

- OAuth2 Keycloak `client_credentials` (Bearer APIM).
- Méthodes simplifiées : `sendMessage`, `sendDocument`, `requestDocuments`, `validAppointment`.
