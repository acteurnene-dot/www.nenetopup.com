# Project Architecture

This is a small static Netlify project. The public site lives at the repository root:

- `index.html` contains the page structure and form markup.
- `styles.css` contains the full responsive visual system.
- `script.js` handles client-side validation, copy-to-clipboard behavior, and form submission.
- `netlify/functions/verify-and-topup.mjs` is the serverless endpoint used by the form.
- `netlify.toml` publishes the repository root and points Netlify to the functions directory.

## Coding Conventions

Keep this project dependency-free unless a requested feature clearly requires a framework or package. Prefer straightforward HTML, CSS, and JavaScript for UI changes. Use Haitian Creole for customer-facing copy to match the current application.

## Non-Obvious Decisions

The verification endpoint does not connect to Natcash or a game top-up provider yet. It validates incoming data and returns a pending-confirmation style success message so the frontend can behave like a real flow without embedding credentials or pretending to transfer diamonds.
