# Hent Playback fil 

WordPress-plugin som lar innloggede brukere med riktig tilgang hente en playback-fil via UKM-domene. Forespørselen valideres mot område/bruker filen tilhører, og filen hentes fra `playback.ukm.no` med et kortlevd signert token.

## Hente en fil

1. Vær innlogget i WordPress.
2. Ha tilgang til arrangementet filen er knyttet til.
3. Åpne eller kall endepunktet med **GET**-parameteren `id` (playback-fil-ID i databasen):
Eksempel (produksjon): `https://sys.ukm.no/getplaybackfile?id=123`

Nettleseren sender med cookies; for `curl` må du sende innloggings-cookie eller bruke en kontekst der du allerede er autentisert.

## Teknisk kort

- Rewrite: sti `getplaybackfile` → intern query var `ukm_getfile`.
- `PlaybackFile::getById(id)` brukes for å finne fil og tilknyttet arrangement.
- `HandleAPICallWithAuthorization` sjekker metode (GET) og tilgang til arrangement.
- Mot playback-server sendes header `X-UKM-Playback-Token` med signert payload.