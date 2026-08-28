# Image de couverture emMCP — prompt pour ChatGPT

## Contraintes DoliStore

- Format carré ou 4:3, au moins 800 × 800 px
- Doit rester lisible en vignette (~200 px de large) dans la liste des modules
- Pas de capture d'écran réelle : c'est une image de couverture, pas une preuve
- Éviter les logos de marques tierces (Anthropic, Claude, OpenAI) — droits

---

## Prompt principal (à coller tel quel)

> Create a clean, modern square illustration for a software module cover image,
> 1024×1024, flat vector style with subtle depth.
>
> Subject: the idea of an AI assistant connected to a business management
> system. On the left, a stylised chat bubble with a small abstract spark or
> star inside, suggesting an AI assistant. On the right, a stylised database or
> server block with layered rectangles, suggesting a business database. Between
> them, a clean horizontal connector — a plug, a link, or a bright line with a
> small node in the middle — showing a live, secure connection.
>
> Add a small closed padlock icon on the connection line, drawn as a simple
> outline, to suggest that the link is permission-controlled. Keep it subtle and
> small, not alarming.
>
> Style: flat vector, geometric, generous white space, no text, no letters, no
> numbers, no brand logos. Rounded corners, thin consistent line weights.
>
> Colour palette: deep navy blue (#1b2a4a) and teal (#0f8b8d) as the main
> colours, with one warm accent in amber (#f2a900) used only for the connection
> line and the spark. Light neutral background (#f7f9fb), no gradient
> backgrounds, no drop shadows heavier than a soft subtle one.
>
> Composition: centred, balanced, with breathing room around the edges so the
> image stays readable when scaled down to a small thumbnail. Professional and
> restrained — this is for a B2B software marketplace, not a consumer app.

---

## Variante A — plus abstraite

Si le rendu est trop littéral (trop « base de données »), remplacer le
paragraphe « Subject » par :

> Subject: two abstract shapes connected by a bright line. On the left, a soft
> rounded shape made of concentric arcs, suggesting conversation and
> intelligence. On the right, a structured grid of small squares, suggesting
> organised business data. The connecting line passes through a small hexagonal
> node in the centre, with a subtle padlock outline inside it.

## Variante B — orientée « flux de travail »

> Subject: a horizontal flow of three elements — a chat bubble, an arrow through
> a small secure gateway shape, and a set of stacked document and invoice icons.
> The gateway in the middle is the focal point, drawn slightly larger, with a
> thin padlock outline.

---

## Consignes de retouche

Si le résultat contient du texte (les générateurs en ajoutent souvent malgré
l'instruction), relancer en ajoutant en fin de prompt :

> Absolutely no text, no words, no letters, no numbers anywhere in the image.

Si les couleurs partent trop dans le violet « IA générique », ajouter :

> Avoid purple and magenta entirely. Stay within navy, teal and amber.

---

## Après génération

1. Vérifier la lisibilité en réduisant l'image à 200 px de large.
2. L'enregistrer dans `marketing/screenshots/` sous le nom
   `emmcp_cover.png`.
3. La déposer comme image principale de la fiche DoliStore, avant les captures
   d'écran.
