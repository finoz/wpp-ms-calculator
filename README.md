# MS Calculator

Lightweight WordPress plugin that renders a configurable scoring calculator: a handful of input parameters (age, gender, ...), a score out.

**Zero external dependencies.** Scoring happens entirely client-side (vanilla JS, no REST calls): the field → points mapping and the result bands travel down embedded in the page as JSON, next to the form. PHP 8.0+, WP 6.0+.

Structure and update mechanism (GitHub auto-update, JSON config editor in admin) mirror the sibling plugin [fnz-forms](https://github.com/finoz/wpp-fnz-forms).

---

## Quick start

1. Upload `ms-calculator/` to `wp-content/plugins/` and activate.
2. Go to **Settings → MS Calculator** and paste your JSON configuration.
3. Add the shortcode to any page: `[ms_calculator id="milan_score"]`

To iterate on the interaction without WordPress: open `preview/milan-score.html` directly in a browser — it statically replicates the markup `msc_render_calculator()` generates from the bundled config-default.json.

---

## Shortcode

```
[ms_calculator id="your_calculator"]
```

`id` must match a key in the `calculators` object of your config.

---

## Configuration (JSON)

The full config lives in **Settings → MS Calculator → Calculator configuration** (stored in the database). Alternatively you can place a file at `wp-content/ms-calculator-config.json` — the admin UI always takes priority.

### Minimal example

```json
{
  "calculators": {
    "milan_score": {
      "title": "Milan Score 2.0",
      "fields": [
        {
          "id": "age",
          "type": "radio",
          "label": "Age",
          "required": true,
          "options": [
            { "value": "age_40", "label": "≤ 40 years", "points": 0 },
            { "value": "age_70", "label": "> 70 years",  "points": 2 }
          ]
        },
        {
          "id": "gender",
          "type": "radio",
          "label": "Gender",
          "required": true,
          "options": [
            { "value": "gender_f", "label": "Female", "points": 0 },
            { "value": "gender_m", "label": "Male",   "points": 3 }
          ]
        }
      ],
      "rule_in": { "min_score": 11, "label": "Rule-in for GERD" },
      "bands": [
        { "min": 0, "max": 1, "label": "Low risk" },
        { "min": 2, "max": 5, "label": "High risk" }
      ]
    }
  }
}
```

The score updates **live** as fields are filled in — there is no "Calculate" button: the intended interaction is the clinician clicking through the patient's values in sequence and reading the result once every required field has been answered (see `assets/ms-calculator.js`).

### Calculator-level keys

| Key            | Required | Default      | Description                                    |
|----------------|----------|--------------|--------------------------------------------------|
| `title`        | no       | —            | Title shown above the form                       |
| `intro`        | no       | —            | Intro copy                                       |
| `fields`       | **yes**  | —            | Array of field objects                           |
| `bands`        | no       | `[]`         | Bands interpreting the total score               |
| `rule_in`      | no       | —            | `{ "min_score": n, "label": "…" }` — badge shown once the total reaches `min_score` |

### Field object

| Key         | Required | Default        | Description                                            |
|-------------|----------|----------------|-----------------------------------------------------------|
| `id`        | **yes**  | —              | Unique within the calculator; becomes the input `name`    |
| `type`      | no       | `select`       | See field types below                                     |
| `label`     | no       | same as `id`   | Visible label text                                         |
| `required`  | no       | `false`        | HTML `required` attribute (not on `checkbox-group`)        |
| `options`   | no       | —              | Required for `select`, `radio`, `checkbox-group`            |
| `points`    | no       | `0`            | `boolean` only: points awarded when checked                 |

### Field types

| Type              | HTML element                   | Scoring                                                   |
|-------------------|----------------------------------|-------------------------------------------------------------|
| `select`          | `<select>`                       | Points of the chosen option                                  |
| `radio`           | group of `<input type="radio">`  | Points of the chosen option                                  |
| `checkbox-group`  | several `<input type="checkbox">`| Sum of points of every checked option                        |
| `boolean`         | single `<input type="checkbox">` | The field's `points`, if checked                              |

`select` exists but is discouraged when there are only a few options (≤5–6): with `radio` the person filling in the form sees every choice at a glance and picks it in one click, instead of the two clicks (open the menu, then choose) a native `<select>` requires — that's why the Milan Score 2.0 in `config-default.json` uses `radio` for all 7 fields, gender included.

**`options` format** (for `select`, `radio`, `checkbox-group`):

```json
"options": [
  { "value": "age_40", "label": "≤ 40 years", "points": 0 },
  { "value": "age_70", "label": "> 70 years",  "points": 2 }
]
```

**`value` convention**: `{field_id}_{number}`, where `{number}` is the clinical threshold with no separators (e.g. `age_4070` for the 40–70 range). It only needs to stay unique *within* the field. For a field with just two options sharing the same cutoff (e.g. BMI `< 25` / `≥ 25`), a leading zero marks "below": `bmi_025` vs `bmi_25`. For a field with no natural clinical number (e.g. gender), fall back to the option's initial: `gender_f` / `gender_m`.

### Result bands (`bands`)

The total score is the sum of the points of every field. The first band whose `[min, max]` range contains the total is shown as the result (both bounds inclusive and optional — omitting `min` means `-∞`, omitting `max` means `+∞`). Setting `min = max` gives a point-exact score → percentage lookup, useful when — as with the Milan Score 2.0 — the score/risk relationship is an empirical table rather than a linear formula.

```json
"bands": [
  { "min": 0, "max": 0, "label": "8% probability of GERD" },
  { "min": 1, "max": 1, "label": "9% probability of GERD" }
]
```

### Rule-in threshold (`rule_in`)

Optional: shows a badge once the score reaches a clinically relevant threshold, independent of the bands.

```json
"rule_in": { "min_score": 11, "label": "Rule-in for GERD" }
```

---

## Generated markup

No "real" CSS is shipped by the plugin — `assets/ms-calculator.css` is a **text placeholder** pending the final design (see next section). Field markup reuses the same classes as [fnz-forms](https://github.com/finoz/wpp-fnz-forms) (`.form-group`, label after/inside the input) so both plugins automatically pick up the same theme-level styling. Full example (2 fields out of 7) in `preview/milan-score.html`:

```html
<div class="msc-calculator-wrap">
  <h3 class="msc-calculator__title">Milan Score 2.0</h3>

  <form class="form msc-calculator" id="milan_score_calculator" data-msc-id="milan_score" novalidate>
    <div class="form-group form-group--radio" role="group" aria-label="Gender">
      <span class="form-group__legend">Gender</span>
      <label for="milan_score_gender_0">
        <input type="radio" id="milan_score_gender_0" name="milan_score_gender" value="gender_f" required>
        <span>Female</span>
      </label>
      <label for="milan_score_gender_1">
        <input type="radio" id="milan_score_gender_1" name="milan_score_gender" value="gender_m" required>
        <span>Male</span>
      </label>
    </div>
    <!-- … the other 6 questions, same pattern … -->
  </form>

  <!-- Result: always visible, updated live on every click -->
  <div class="msc-result" id="milan_score_result" data-msc-result aria-live="polite">
    <p class="msc-result__score">Score: <span data-msc-result-score>0</span></p>
    <p class="msc-result__badge" data-msc-result-badge hidden></p>
    <p class="msc-result__label" data-msc-result-label>Fill in all fields to calculate the risk.</p>
    <p class="msc-result__description" data-msc-result-description></p>
  </div>

  <script type="application/json" id="milan_score_msc-config">{ "fields": […], "bands": […], "rule_in": {…} }</script>
</div>
```

---

## How scoring works

No server call, no "Calculate" button: `assets/ms-calculator.js` listens for `change` events on the form, recalculates the score on every click by reading the `<script type="application/json">` block embedded next to the form, and updates the result panel in real time. The risk band (and the optional rule-in badge) only appears once every required field has been answered — the partial score stays visible while filling in the form.

If tracking submissions (leads, analytics, emailing the result) becomes a requirement later, a REST endpoint modeled on the one already in fnz-forms would need to be added — not included here since the current use case doesn't call for it.

---

## Roadmap: SCSS → CSS via Vite

`assets/ms-calculator.css` is hand-written today, as a placeholder. Once the visual design is defined, it will be replaced by a Vite + SCSS build (`assets/scss` → `assets/dist`) identical to the one already used in [wpt-lomais](https://github.com/finoz/wpt-lomais) — not implemented yet in this version.

---

## Auto-updates from GitHub

The plugin checks its own GitHub repository for new releases and shows the standard WordPress update notification — no extra configuration needed.

```php
define( 'MSC_GITHUB_REPO', 'finoz/wpp-ms-calculator' );
```

Flow: bump `Version:` in `ms-calculator.php` → create a GitHub release with a matching tag (e.g. `v0.2.0`) → WordPress detects the new version within 12 hours → one-click update from Dashboard → Updates.

**Private repos** — add a token with `contents:read` scope in `wp-config.php`:

```php
define( 'MSC_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
```

**Forks** — point to a different repo without touching plugin code:

```php
add_filter( 'msc_github_repo', fn() => 'other-user/my-fork' );
```

---

## Advanced

### Override the config file path

```php
add_filter( 'msc_config_path', fn() => '/var/secrets/my-calculators.json' );
```

---

## Changelog

### 0.2.0 (in progress)
Replaced config-default.json with the real Milan Score 2.0 data (7 fields, point-exact 0–20 score → percentage lookup, rule-in threshold ≥11). Interaction switched to live-update (no "Calculate" button): the result updates on every click. Added `preview/milan-score.html` to test the interaction outside WordPress. Option `value`s follow the `{field_id}_{number}` convention (e.g. `age_4070`, `bmi_025` for the below-cutoff option). All copy converted to English.

### 0.1.0
Initial structure: plugin bootstrap, auto-updater, JSON editor in admin, field rendering (select/radio/checkbox-group/boolean), client-side scoring engine, placeholder CSS.
