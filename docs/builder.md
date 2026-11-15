# Visual page builder

Open any page (or other content type with an editor) and choose **Design with the visual builder**.

- **Add**: drag blocks from the left (layout, basic elements, ready-made sections, dynamic blocks, your saved blocks).
- **Style**: select an element, then use the Style panel. The site's colours are one click away (click = text colour,
  Shift-click = background), the heading/body fonts are listed first, and any CSS variable such as
  `var(--color-primary)` works in every field. Switch to Tablet or Mobile at the top to style just that size.
- **Settings**: link targets, image alt text and other attributes of the selected element.
- **Code**: `</>` edits the page's HTML and CSS directly. Template tags (`{{title}}`, `{{field:name}}`,
  `{{form:slug}}`, `{{#query type="post" limit="3"}}…{{/query}}`) are filled in for visitors.
- **Save** (Ctrl/⌘ S) keeps a revision; **Publish/Update** makes it live. Choose *With site header & footer* or *Blank page*.
- **Classic editor**: the ⋯ menu switches back; the design is kept and you can return to it any time.

## Your existing design is never changed

- Nothing converts automatically. Existing pages, posts and code Templates keep rendering through the theme and
  template engine exactly as before.
- Converting is opt-in, one item at a time. Opening the builder brings the item's current content in as editable
  parts. Saving keeps a revision, and remembers the original content.
- **Use classic editor** (in the editor or the builder's ⋯ menu) puts the original content back exactly; the visual
  design is kept, so you can switch again later.
- Content with template logic (loops, conditions, tags inside attributes) asks for confirmation before converting.
  Code Templates are not converted by the builder.
- The canvas loads your theme's stylesheet, your design system and a locked preview of the real site header and
  footer, so editing looks like the live site. The preview is never saved into the page.

What visitors get: the page's HTML and one small `<style>` block with class-based rules (and media queries for
tablet/mobile). No builder JavaScript, and the page cache works as usual.

Turn it off, or limit it to some content types, in Settings → Content & media → Visual builder.

## Updating GrapesJS

The version is pinned in `package.json`. Dependabot proposes updates after 14 days; the *Builder test* workflow runs
an end-to-end test with screenshots. Patch versions merge into `develop` automatically when green, the *Refresh
vendored files* workflow then commits the new files. To update by hand: change the version, `npm install`, `npm run vendor`,
`npm run test:builder` (needs `SLATE_URL`, `SLATE_USER`, `SLATE_PASS` of a test site).
