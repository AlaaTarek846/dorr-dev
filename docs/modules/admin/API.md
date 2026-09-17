# Admin — API

Base: `/api/admin/v1` (middleware: `locale`)

## Auth

| Method | Endpoint | Middleware |
|--------|----------|------------|
| POST | `/login` | guest:admin_api |
| POST | `/check-token` | guest:admin_api |
| GET | `/me` | auth:admin_api |
| POST | `/logout` | auth:admin_api |

## Profile

| Method | Endpoint |
|--------|----------|
| POST | `/profile` |
| PUT | `/profile/password` |

## Admins

| Method | Endpoint |
|--------|----------|
| GET/POST | `/admins` |
| GET/PUT/PATCH/DELETE | `/admins/{admin}` |
| POST | `/admins/delete-multiple` |
| PATCH | `/admins/{admin}/status` |

Also see General, User (admin-managed), AI, Provider module API docs.

Full spec: [../../06-API-SPECIFICATION.md](../../06-API-SPECIFICATION.md)
