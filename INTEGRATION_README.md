# SkillSpan Laravel ↔ FastAPI Integration

## Architecture

`Frontend -> Laravel -> FastAPI -> Laravel -> Database -> Frontend`

The frontend never calls FastAPI directly. Laravel remains the source of truth for users, roles, skills, evaluations, permissions, and readiness records.

## Laravel endpoints

- `POST /api/readiness/calculate`
- `GET /api/readiness/latest`

Both endpoints require `Authorization: Bearer <Sanctum token>`.

### Calculate request

```json
{
  "career_role_id": 3
}
```

If `career_role_id` is omitted, Laravel uses `student_profiles.primary_career_role_id`.

### FastAPI request used by the current Data Science service

Laravel sends:

```json
{
  "user_id": 15,
  "target_role": "Data Analyst",
  "skills": [
    {
      "skill_name": "SQL",
      "current_level": 2.5,
      "required_level": 4.0,
      "importance_weight": 45.0,
      "is_critical": true
    }
  ]
}
```

The Laravel database stores normalized importance weights as `0..1`; the current FastAPI contract accepts relative weights `0..100`. Laravel multiplies all normalized weights by `100`, preserving their ratios.

## FastAPI local service

Run from the Data Science repository:

```powershell
python -m venv .venv
.\.venv\Scripts\activate
python -m pip install -r requirements.txt
uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

Check:

- `GET http://127.0.0.1:8001/health`
- `GET http://127.0.0.1:8001/docs`
- `POST http://127.0.0.1:8001/api/v1/skill-gap`

## Environment variables

```dotenv
DATA_SCIENCE_SERVICE_URL=http://127.0.0.1:8001
DATA_SCIENCE_SERVICE_TIMEOUT=10
DATA_SCIENCE_ALGORITHM_VERSION=skill-gap-v1
```

## Important current-service limitation

The supplied FastAPI response does not include a career-role ID/version or an algorithm-version field. Laravel therefore validates the returned `user_id`, target-role title, score ranges, and skill results, while the stored `algorithm_version` comes from `DATA_SCIENCE_ALGORITHM_VERSION`.

## Error behavior

- Missing learner profile: `422`
- Missing skill evaluation: `422 ASSESSMENT_INCOMPLETE`
- FastAPI validation (`422`): forwarded as integration validation error; no result is stored
- FastAPI server error / timeout / connection failure: `503`; no result is stored
- Unexpected FastAPI response: `502`; no result is stored

## End-to-end check

1. Start MySQL and the Laravel app.
2. Start the FastAPI service on port `8001`.
3. Log in through Laravel and copy the Sanctum token.
4. Ensure the authenticated learner has an approved career role and a latest `skill_evaluations` row for every required role skill.
5. Call `POST /api/readiness/calculate`.
6. Verify a row is created in `readiness_results`.
7. Call `GET /api/readiness/latest` and verify the saved result is returned.
