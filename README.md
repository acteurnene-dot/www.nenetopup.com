# NENE TOP UP

NENE TOP UP is a static Netlify site for submitting Free Fire diamond top-up requests in Haitian Creole. It includes a polished payment form, Natcash payment instructions, client-side validation, and a Netlify Function that validates incoming requests and returns a clear pending-confirmation response.

## Key Technologies

- Plain HTML, CSS, and JavaScript for the frontend
- Netlify Functions for the request verification endpoint
- Netlify static hosting with `netlify.toml`

## Run Locally

Use Netlify Dev so the static page and function endpoint run together:

```bash
netlify dev --port 8889
```

Then open the local URL printed by the command.

## Production Notes

The current function validates request shape and simulates a successful submission. Connecting real Natcash verification and a real Free Fire top-up provider requires provider credentials and API contracts, which should be stored as Netlify environment variables.
