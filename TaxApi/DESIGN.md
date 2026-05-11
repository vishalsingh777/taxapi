# INSEAD TaxApi — Technical Documentation

**Module:** `Insead_TaxApi` | **Magento:** 2.4.8+ | **PHP:** 8.3+ | **Version:** 1.0.0

---

## 1. What This Module Does

This module turns Magento into a **centralised REST API tax engine** for INSEAD's external billing systems (PeopleSoft, Salesforce, etc.).

Magento holds **no product catalogue**. Instead, a small set of generic "tax carrier" products are created in Magento — one per programme type and entity combination — each assigned the correct product tax class. The external system calls the API with its invoice data, and Magento applies its configured tax rules to return the exact rate and amounts.

**Why this approach:**
- Single source of truth for tax rules — configure once in Magento, all billing systems get the same result
- No tax logic duplication across PeopleSoft, Salesforce, or any other system
- Complete audit trail of every tax calculation
- Tax rule updates take effect immediately across all systems

---

## 2. How It Works — The Three Engine Inputs

Magento's tax engine needs exactly three inputs to find a matching rule and return a rate:

```
Customer Tax Class  +  Product Tax Class  +  Billing Country
        ↓                      ↓                    ↓
                    → TAX RULE MATCH → RATE + COMMENT
```

The module's job is to **derive these three inputs** from the incoming API fields, then call the Magento tax engine.

### 2.1 Product Tax Class — via SKU lookup

The module constructs a Magento product SKU from three request fields:

```
{legalEntity}_{tax_product_code}_{programmeDeliveryLocation}
```

Examples:
- `SGP` + `OL_OOP_NA` + `NA` → `SGP_OL_OOP_NA_NA`
- `FBL` + `IP_OEP_SHORT` + `FBL` → `FBL_IP_OEP_SHORT_FBL`
- `UAE` + `OL_FOOD_NA` + `NA` → `UAE_OL_FOOD_NA_NA`

The module loads the Magento product with that SKU and reads its assigned product tax class. If no product exists for the constructed SKU, the API returns a `400 error` telling you exactly which SKU to create.

### 2.2 Customer Tax Class — derived from three flags

The customer tax class name is built dynamically:

| customerType | isValidVat | gstExempt | Magento Class |
|-------------|-----------|----------|--------------|
| B2B | false | false | `B2B` |
| B2B | true | false | `B2B_VAT` |
| B2B | false | true | `B2B_GST_EXEMPT` |
| B2B | true | true | `B2B_VAT_GST_EXEMPT` |
| B2C | false | false | `B2C` |
| B2C | true | false | `B2C_VAT` |
| B2C | false | true | `B2C_GST_EXEMPT` |
| B2C | true | true | `B2C_VAT_GST_EXEMPT` |

All eight classes must be created in Magento and mapped to customer groups.

### 2.3 Billing Country — with one override

In almost all cases the `billingCountry` from the request is passed directly to the tax engine.

**One exception — OOP Singapore physical presence rule:**

If `legalEntity = SGP` AND `participantCountry = SG`, the billing country is overridden to `SG` internally. This handles the case where a customer's billing address is outside Singapore but they will be physically present in Singapore during the OOP programme — Singapore GST applies.

---

## 3. Request Fields

### Required fields

| Field | Type | Allowed values / format |
|-------|------|------------------------|
| `legalEntity` | string | `FBL`, `SGP`, `UAE`, `USA` |
| `customerType` | string | `B2B`, `B2C` |
| `isValidVat` | boolean | `true` or `false` |
| `gstExempt` | boolean | `true` or `false` |
| `billingCountry` | string | ISO-2 e.g. `SG`, `FR`, `DE`, `AE`, `US` |
| `subtotal` | float | >= 0. Sum of all line items (price × qty). Stored in log. |
| `currency` | string | ISO-3 e.g. `SGD`, `EUR`, `USD` |
| `programmeDeliveryLocation` | string | `FBL`, `SGP`, `UAE`, `USA`, or `NA` |
| `lineItems` | array | At least one item — see below |

### Optional fields

| Field | Type | Description |
|-------|------|-------------|
| `participantCountry` | string | ISO-2. Where participant is present during programme. Triggers SGP override. |
| `vatNumber` | string | VAT/tax ID. Stored in audit log only. |
| `billingSystem` | string | Source system. e.g. `PeopleSoft`, `Salesforce`. Stored in log. |

### Line item fields

Each object in the `lineItems` array:

| Field | Required | Type | Description |
|-------|----------|------|-------------|
| `tax_product_code` | Yes | string | `OL_OOP_NA`, `IP_OEP_SHORT`, `IP_OEP_LONG`, `IP_CSP_SHORT`, `IP_CSP_LONG`, `OL_CASES_NA`, `OL_FOOD_NA` |
| `price` | Yes | float | Unit price >= 0 |
| `qty` | Yes | float | Quantity > 0 |
| `sku` | No | string | External system SKU. Echoed in response. Logged. |
| `name` | No | string | Product name. Echoed in response. Logged. |

All string fields are **case-insensitive** — `fbl`, `FBL`, `Fbl`, `ol_oop_na`, `OL_OOP_NA` are all accepted.

---

## 4. Programme Types and SKU Patterns

### tax_product_code structure

```
{deliveryMode}_{programmeType}_{duration}

OL = Online    IP = In-Person    NA = Not applicable
```

| Programme | tax_product_code | Duration | Entities | Notes |
|-----------|-----------------|----------|----------|-------|
| OOP — Online Open Programme | `OL_OOP_NA` | None | All 4 | Online only |
| OEP — On-site Executive Programme | `IP_OEP_SHORT` or `IP_OEP_LONG` | Short/Long | All 4 | Campus-based |
| CSP — Custom Specific Programme | `IP_CSP_SHORT` or `IP_CSP_LONG` | Short/Long | All 4 | Campus-based |
| Cases | `OL_CASES_NA` | None | FBL, USA | — |
| Food | `OL_FOOD_NA` | None | All 4 | — |

### SKU reference

| Scenario | Constructed SKU |
|----------|----------------|
| OOP, billed SGP | `SGP_OL_OOP_NA_NA` |
| OOP, billed FBL | `FBL_OL_OOP_NA_NA` |
| OEP short, France campus, billed FBL | `FBL_IP_OEP_SHORT_FBL` |
| OEP long, SG campus, billed SGP | `SGP_IP_OEP_LONG_SGP` |
| OEP short, France campus, billed SGP | `SGP_IP_OEP_SHORT_FBL` |
| CSP long, UAE campus, billed FBL | `FBL_IP_CSP_LONG_UAE` |
| Cases, billed FBL | `FBL_OL_CASES_NA_NA` |
| Cases, billed USA | `USA_OL_CASES_NA_NA` |
| Food, billed SGP | `SGP_OL_FOOD_NA_NA` |

---

## 5. Legal Entities and Fallback Rates

| Entity | Location | Fallback Rate |
|--------|----------|--------------|
| `FBL` | Fontainebleau, France | 20% |
| `SGP` | Singapore | 9% |
| `UAE` | Abu Dhabi | 5% |
| `USA` | North America | 0% |

Fallback rates apply **only when no Magento tax rule matches** the given combination. They are emergency safety nets — not a substitute for proper rule configuration. When a fallback is used, `status` is `warning` and `fallback_applied: true` appears in the response.

---

## 6. Response Fields

### Top-level

| Field | Present when | Description |
|-------|-------------|-------------|
| `status` | Always | `success`, `warning`, or `error` |
| `response_code` | Always | `200` (ok), `400` (bad input), `500` (server error) |
| `message` | Warning or error | Human-readable description |
| `tax_rate` | Calculated | Average rate % across all items |
| `subtotal` | Calculated | Engine-computed total before tax |
| `tax_amount` | Calculated | Total tax across all items |
| `grand_total` | Calculated | subtotal + tax_amount |
| `currency` | Calculated | ISO-3 echoed from request |
| `tax_comment` | Rule matched | Custom comment from matched tax rule |
| `fallback_applied` | Fallback used | `true` when no rule matched |
| `line_items` | Calculated | Per-item tax breakdown array |

### Per-item breakdown (each object in `line_items`)

| Field | Description |
|-------|-------------|
| `code` | Item index: `item_0`, `item_1`, etc. |
| `sku` | External SKU echoed from request |
| `name` | Product name echoed from request |
| `tax_product_code` | Code used for SKU construction |
| `price` | Unit price |
| `qty` | Quantity |
| `row_total` | price × qty (before tax) |
| `tax_rate` | Rate % applied to this item |
| `tax_amount` | Tax on this item |
| `row_total_incl_tax` | row_total + tax_amount |

---

## 7. Status Values

| Status | Meaning | Action |
|--------|---------|--------|
| `success` | Magento rule matched and rate returned | None |
| `warning` | No rule matched — fallback rate applied | Review tax rule configuration |
| `error` | Invalid input or SKU not found in Magento | Fix input or create missing generic product |

---

## 8. Example Requests and Responses

### OOP — Singapore B2C customer

```json
{
  "legalEntity": "SGP",
  "customerType": "B2C",
  "isValidVat": false,
  "gstExempt": false,
  "billingCountry": "SG",
  "subtotal": 20900.00,
  "currency": "SGD",
  "programmeDeliveryLocation": "NA",
  "billingSystem": "PeopleSoft",
  "lineItems": [
    { "tax_product_code": "OL_OOP_NA", "sku": "EXT-001", "price": 10000.00, "qty": 2 },
    { "tax_product_code": "OL_OOP_NA", "sku": "EXT-002", "price": 900.00,   "qty": 1 }
  ]
}
```

SKUs constructed: `SGP_OL_OOP_NA_NA` for both items.

Response:
```json
{
  "status": "success",
  "response_code": 200,
  "tax_rate": 9.00,
  "subtotal": 20900.00,
  "tax_amount": 1881.00,
  "grand_total": 22781.00,
  "currency": "SGD",
  "tax_comment": "GST @9% No VAT",
  "line_items": [
    { "code": "item_0", "price": 10000.00, "qty": 2, "row_total": 20000.00, "tax_rate": 9.00, "tax_amount": 1800.00, "row_total_incl_tax": 21800.00 },
    { "code": "item_1", "price": 900.00,   "qty": 1, "row_total": 900.00,   "tax_rate": 9.00, "tax_amount": 81.00,   "row_total_incl_tax": 981.00   }
  ]
}
```

### OOP — SGP override (non-SG customer physically in Singapore)

```json
{
  "legalEntity": "SGP",
  "customerType": "B2B",
  "isValidVat": false,
  "gstExempt": false,
  "billingCountry": "AU",
  "participantCountry": "SG",
  "subtotal": 10000.00,
  "currency": "SGD",
  "programmeDeliveryLocation": "NA",
  "lineItems": [{ "tax_product_code": "OL_OOP_NA", "price": 10000.00, "qty": 1 }]
}
```

`billingCountry=AU` is overridden to `SG` internally because `legalEntity=SGP` and `participantCountry=SG`.

### OEP — EU B2B VAT-registered, France campus

```json
{
  "legalEntity": "FBL",
  "customerType": "B2B",
  "isValidVat": true,
  "gstExempt": false,
  "billingCountry": "DE",
  "vatNumber": "DE123456789",
  "subtotal": 15000.00,
  "currency": "EUR",
  "programmeDeliveryLocation": "FBL",
  "billingSystem": "Salesforce",
  "lineItems": [{ "tax_product_code": "IP_OEP_SHORT", "price": 15000.00, "qty": 1 }]
}
```

Customer class resolves to `B2B_VAT`. SKU: `FBL_IP_OEP_SHORT_FBL`.

---

## 9. INSEAD Tax Comment

Each Magento tax rule has a custom `insead_tax_comment` field. When a rule matches, this comment is returned in the response `tax_comment` field. The external system uses this for invoice display.

Examples:

| Scenario | Comment |
|----------|---------|
| Singapore GST standard | `GST @9% No VAT` |
| EU reverse charge (B2B VAT) | `Reverse-charge: Customer to pay the VAT` |
| EU MOSS (B2C) | `GST @0% — EU VAT to pay via MOSS` |
| Non-taxable distance learning | `GST @0% — No VAT — Distance learning` |
| USA | `No tax — USA` |

Configure at **Stores → Taxes → Tax Rules** → edit rule → fill in the INSEAD Tax Comment field.

---

## 10. Audit Logging

Every API call is saved to `insead_taxapi_calculation_log` regardless of outcome (success, warning, or error). The log stores:

- All request fields including `legalEntity`, `participantCountry`, `isValidVat`, `gstExempt`
- Constructed SKU(s) shown in the `product_class` column
- Full request JSON and full response JSON
- Applied tax rate, amounts, tax comment
- Timestamp

**Admin view:** INSEAD Tax → Calculation Logs

**Features:**
- Filter by status, date range, legal entity, billing country, delivery location, billing system
- Mass delete selected rows
- Export to CSV or Excel XML

**Log cleanup:** Stores → Configuration → INSEAD → Tax Calculation → Log Retention (Days). Default 90 days. Cron runs nightly at 02:00 server time. Set to 0 to disable.

---

## 11. Magento Setup Checklist

### Customer tax classes
Create at **Stores → Taxes → Tax Classes → Customer Tax Classes**:
`B2B`, `B2B_VAT`, `B2B_GST_EXEMPT`, `B2B_VAT_GST_EXEMPT`, `B2C`, `B2C_VAT`, `B2C_GST_EXEMPT`, `B2C_VAT_GST_EXEMPT`

### Customer groups
Map each group to the appropriate class at **Stores → Customers → Customer Groups**.

### Generic products
Create one simple product per SKU. Only SKU and product tax class matter. Price, stock, visibility are irrelevant.

### Tax rates
Create at **Stores → Taxes → Tax Zones and Rates**.

### Tax rules
Create at **Stores → Taxes → Tax Rules**. Set Customer Tax Class + Product Tax Class + Tax Rate. Fill in the **INSEAD Tax Comment** field.

### API integration token
**System → Integrations → Add New** → grant `Self` resource → Activate → copy Access Token.

---

## 12. Installation

```bash
mkdir -p app/code/Insead/TaxApi
cp -r /path/to/module/* app/code/Insead/TaxApi/

# Remove old module if present
rm -rf app/code/Insead/Tax/

php bin/magento module:enable Insead_TaxApi
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:flush
```

---

## 13. Authentication

```bash
# Get admin token (expires)
curl -X POST "https://insead.local/rest/V1/integration/admin/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"your_password"}'

# Use in requests:
# Authorization: Bearer YOUR_TOKEN
```

For production use System → Integrations to create a non-expiring integration token.

---

*Copyright © INSEAD. All rights reserved.*
