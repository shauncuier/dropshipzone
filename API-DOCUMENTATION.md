# Dropshipzone API Documentation

Welcome to Dropshipzone APIs Documentation. Developers have extensive access to Dropshipzone APIs in order to build new services and features for merchants. This section will help you understand which parts of Dropshipzone you can access and how to work with them.

## ⚠️ Rate Limiting (Throttle Limit)

Please note the throttle limits:
- **Maximum requests per minute**: 60
- **Maximum requests per hour**: 600

Dropshipzone API Gateway will fail limit-exceeding requests and return error responses to the client.

---

## Table of Contents

1. [Authentication](#authentication)
2. [Categories](#categories)
3. [Products](#products)
4. [Stock](#stock)
5. [Orders](#orders)
6. [Shipping](#shipping)

---

## Authentication

### Create Access Token

Create an access token based on user access information. Token will expire in 15 minutes; after expiration, a new token will be required.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/auth
```

**Headers:**
```json
{
    "Content-Type": "application/json"
}
```

**Request Body:**
| Field | Type | Description |
|-------|------|-------------|
| email | String | API user email |
| password | String | API user password |

**Request Example:**
```json
{
    "email": "apiuseremail@apiuseremail.com",
    "password": "123456"
}
```

**Success Response (200):**
```json
{
    "iat": 1569986936,
    "exp": 1570206536,
    "token": "xxxxxxxxxxxxx"
}
```

**Error Response (500):**
```json
{
    "code": "InternalServer",
    "message": "boom!"
}
```

---

## Categories

### V2 Get All Categories

Get a list of categories and category information.

**Endpoint:**
```
GET https://api.dropshipzone.com.au/v2/categories
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Success Response (200):**
```json
[
    {
        "category_id": 1,
        "title": "Appliances",
        "parent_id": 0,
        "path": "1/2/3",
        "is_anchor": 1,
        "is_active": 1,
        "include_in_menu": 1
    },
    {
        "category_id": 3,
        "title": "Tools & Auto",
        "parent_id": 2,
        "path": "1/2/3",
        "is_anchor": 1,
        "is_active": 1,
        "include_in_menu": 1
    }
]
```

**Error Response (400):**
```json
{
    "code": "400",
    "message": "Bad Request"
}
```

---

## Products

### V2 Get Products

Retrieve a list of products.

**Endpoint:**
```
GET https://api.dropshipzone.com.au/v2/products
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Query Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| category_id | Number | No | - | Category ID |
| enabled | Boolean | No | true | Whether the product is enabled |
| in_stock | Boolean | No | - | Whether the product is in stock |
| au_free_shipping | Boolean | No | - | Whether the product has free shipping in Australia |
| nz_available | Boolean | No | - | Whether the product is available in New Zealand |
| on_promotion | Boolean | No | - | Whether the product is on promotion |
| new_arrival | Boolean | No | - | Whether the product is newly launched |
| supplier_ids | String | No | - | Comma separated supplier IDs (up to 50) |
| exclude_supplier_ids | String | No | - | Exclude products from certain suppliers (up to 50) |
| skus | String | No | - | Comma separated product SKUs (up to 100) |
| keywords | String | No | - | Comma separated keywords (up to 20) |
| page_no | Number | No | 1 | Page number |
| limit | Number | No | 40 | Page size (min: 40, max: 200) |
| sort_by | String | No | - | Sort by field (allowed: `price`) |
| sort_order | String | No | - | Sort order (allowed: `asc`, `desc`) |

**Success Response (200) - After 30th Sep 2025:**
```json
{
    "result": [
        {
            "l1_category_id": 719,
            "l1_category_name": "Appliances",
            "l2_category_id": 720,
            "l2_category_name": "Air Conditioners",
            "l3_category_id": 722,
            "l3_category_name": "Evaporative Coolers",
            "entity_id": 186573,
            "Category": "Appliances > Air Conditioners > Evaporative Coolers",
            "ETA": "",
            "discontinued": "No",
            "discontinuedproduct": "No",
            "product_status": 1,
            "RrpPrice": 58.61,
            "RRP": {
                "Standard": 58.61
            },
            "vendor_id": "201",
            "Vendor_Product": 1,
            "brand": "Does not apply",
            "cbm": 0.0016,
            "colour": "",
            "cost": 29.31,
            "currency": "AUD",
            "desc": "product description",
            "eancode": "729604212984",
            "height": 8.5,
            "length": 15,
            "weight": 0.336,
            "width": 12.5,
            "in_stock": "1",
            "status": "In Stock",
            "stock_qty": 44,
            "sku": "V201-W12898984",
            "special_price": null,
            "special_price_from_date": "",
            "special_price_end_date": "",
            "rebate_percentage": 10,
            "rebate_start_date": "2025-05-14 00:00:00",
            "rebate_end_date": "2025-05-16 23:59:59",
            "title": "12V Portable Car Fan Heater Vehicle Heating Windscreen Defroster Demister 300W",
            "website_url": "https://www.dropshipzone.com.au/12v-portable-car-fan-heater-vehicle-heating-windscreen-defroster-demister-300w.html",
            "updated_at": 1720503883,
            "price": 29.31,
            "gallery": [
                "https://cdn.dropshipzone.com.au/media/catalog/product/V/2/V201-W12898984-186573-00.png",
                "https://cdn.dropshipzone.com.au/media/catalog/product/V/2/V201-W12898984-186573-01.jpg"
            ],
            "freeshipping": "0",
            "is_new_arrival": true,
            "is_direct_import": true
        }
    ],
    "total": 91,
    "total_pages": 5,
    "current_page": 1
}
```

**Error Response (400):**
```json
{
    "code": "ResourceNotFound",
    "message": "Bad Request"
}
```

### Get Stock

Get stock level of a product. **Note:** 10 days is the maximum time range.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/stock
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Request Body:**
| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| start_time | String | Yes | - | Start time (e.g., "2020-8-03 05:11:44") |
| end_time | String | Yes | - | End time (e.g., "2020-8-10 05:11:44") |
| page_no | Number | No | 1 | Page number |
| limit | Number | No | 40 | Limit (min: 40, max: 160) |
| skus | String | Yes | - | Product SKUs (comma separated) |

**Request Example:**
```json
{
    "start_time": "2020-8-03 05:11:44",
    "end_time": "2020-8-10 05:11:44",
    "page_no": 1,
    "limit": 60,
    "skus": "FURNI-L-COF01-BK-AB"
}
```

**Success Response (200):**
```json
{
    "result": [
        {
            "sku": "FURNI-L-COF01-BK-AB",
            "created_at": "2020-08-03T05:36:16.000Z",
            "new_qty": "0.00",
            "status": "Out Of Stock"
        }
    ],
    "total": 1,
    "page_no": 1,
    "limit": 60
}
```

**Error Response (500):**
```json
{
    "code": "InternalServer",
    "message": "caused by InvalidArgumentError: The range must be less than 10 days apart."
}
```

---

## Orders

### Get Orders

Retrieve order information.

**Endpoint:**
```
GET https://api.dropshipzone.com.au/orders
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxx",
    "Content-Type": "application/json"
}
```

**Query Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| order_ids | String | No | - | Order number |
| start_date | String | No | - | Start date (formats: Y-m-d H:m:s, YYYY-MM-DDTHH:mm:ss.sssZ) |
| end_date | String | No | - | End date (formats: Y-m-d H:m:s, YYYY-MM-DDTHH:mm:ss.sssZ) |
| status | String | No | - | Order status: `processing`, `complete`, `cancelled` |
| page_no | Number | No | 1 | Page number |
| limit | Number | No | 40 | Limit (min: 40, max: 160) |

**Request Example:**
```
https://api.dropshipzone.com.au/orders?order_ids=102010799&start_date=2022-01-01&end_date=2022-01-23
```

**Note:** The time range must be less than or equal to 14 days apart.

**Error Response (404):**
```json
{
    "status": -1,
    "errmsg": "The time range must be less than or equal to 14 days apart."
}
```

### Place Order

Place an order in Dropshipzone. After this API is called, the order will be created in the Dropshipzone account as a "Not Submitted" order. User should then login to Dropshipzone website and pay for the orders.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/placingOrder
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Request Body:**
| Field | Type | Description |
|-------|------|-------------|
| your_order_no | String | Your unique Order Number |
| first_name | String | Consignee first name |
| last_name | String | Consignee last name |
| address1 | String | Consignee address first line |
| address2 | String | Consignee address second line |
| suburb | String | Consignee address suburb |
| state | String | Consignee address state |
| postcode | String | Consignee address postcode |
| telephone | String | Consignee telephone |
| comment | String | Order notes |
| order_items | Array | Array of order items with `sku` and `qty` |

**Request Example:**
```json
{
    "your_order_no": "PM2132342434",
    "first_name": "John",
    "last_name": "Baker",
    "address1": "add1",
    "address2": "add2",
    "suburb": "Eugowra",
    "state": "Australian Capital Territory",
    "postcode": "2806",
    "telephone": "0412345678",
    "comment": "comment test456",
    "order_items": [
        {
            "sku": "FURNI-E-TOY200-8BIN-WH",
            "qty": 1
        },
        {
            "sku": "MOC-09M-2P-BK",
            "qty": 3
        }
    ]
}
```

**Success Response (200):**
```json
[
    {
        "status": 1,
        "serial_number": "P02100689"
    }
]
```

**Error Responses (200):**
```json
[
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "order_id not has to be unique"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "The postcode cannot be found"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "The postcode 3000 does not exist in the Sydney city"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "The email cannot be found"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "Sorry, we do not have enough SKU: FURNI-E-TOY200-8BIN-WH in stock to fulfil your order"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "The SKU: FURNI-E-TOY200-8BIN-WH does not exist"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "order_items sku is required"
    },
    {
        "status": -1,
        "serial_number": "P02100689",
        "errmsg": "order_items qty is required"
    }
]
```

---

## Shipping

### V2 Get Zone Mapping (NEW)

Get Zone Mapping Data.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/v2/get_zone_mapping
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Request Body:**
| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| postcode | String | No | - | Postcodes to map (comma separated, e.g., "2823,6302") |
| page_no | Number | No | 1 | Page number |
| limit | Number | No | 40 | Limit (min: 40, max: 160) |

**Request Example:**
```json
{
    "postcode": "2823,6302,2002,4001",
    "page_no": 1,
    "limit": 40
}
```

**Success Response (200):**
```json
{
    "result": [
        {
            "postcode": "2823",
            "standard": "nsw_r"
        },
        {
            "postcode": "6302",
            "standard": "wa_r",
            "defined": "wa_near_country",
            "advanced": "sw_wa"
        },
        {
            "postcode": "2002",
            "standard": "nsw_m",
            "advanced": "sydney_pob"
        },
        {
            "postcode": "4001",
            "standard": "qld_m",
            "defined": "brisbane"
        }
    ],
    "total": 4,
    "total_pages": 1,
    "current_page": 1,
    "page_size": 40,
    "code": 1,
    "message": "ok"
}
```

**Error Response (500):**
```json
{
    "code": 0,
    "data": [],
    "message": "no data"
}
```

### V2 Get Zone Rates (NEW)

Get Zone Rates for shipping calculations.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/v2/get_zone_rates
```

**Headers:**
```json
{
    "Authorization": "jwt xxxxxxxxxxxxx",
    "Content-Type": "application/json"
}
```

**Request Body:**
| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| skus | String | No | - | SKUs to get rates for (comma separated, e.g., "3DF-ABS-1KG-BK,3DP-ED3-GB-BK") |
| page_no | Number | No | 1 | Page number |
| limit | Number | No | 40 | Limit (min: 40, max: 160) |

**Request Example:**
```json
{
    "skus": "AES-KB22K,AES-T001B,AES-T002",
    "page_no": 1,
    "limit": 40
}
```

**Success Response (200):**
```json
{
    "result": [
        {
            "sku": "AES-KB22K",
            "standard": {
                "act": "3",
                "nsw_m": "20",
                "nsw_r": "0",
                "nt_m": "0",
                "nt_r": "0",
                "qld_m": "0",
                "qld_r": "0",
                "remote": "0",
                "sa_m": "0",
                "sa_r": "0",
                "tas_m": "0",
                "tas_r": "0",
                "vic_m": "0",
                "vic_r": "0",
                "wa_m": "0",
                "wa_r": "0",
                "nz": "9999",
                "active": true
            },
            "defined": {
                "adelaide": "4",
                "australian_antarctic_territory": "0",
                "ballarat": "0",
                "brisbane": "0",
                "canberra": "0",
                "christmas_island": "0",
                "cocos_islands": "0",
                "coolangatta": "0",
                "geelong": "0",
                "gold_coast": "0",
                "gosford": "0",
                "ipswich": "0",
                "melbourne": "0",
                "newcastle": "0",
                "norfolk_island": "0",
                "nsw_country_north": "0",
                "nsw_country_south": "0",
                "nt_far_country": "0",
                "nt_near_country": "0",
                "perth": "0",
                "qld_far_country": "0",
                "qld_mid_country": "0",
                "qld_near_country": "0",
                "sa_country": "0",
                "sunshine_coast": "0",
                "sydney": "9999",
                "tasmania_country": "0",
                "tasmania_metro": "0",
                "tweed_heads": "0",
                "vic_far_country": "0",
                "vic_near_country": "0",
                "wa_far_country": "0",
                "wa_near_country": "0",
                "wollongong": "0",
                "active": true
            },
            "advanced": {
                "adelaide": "6",
                "adelaide_pob": "0",
                "adelaide_fringe": "0",
                "adelaide_hills": "0",
                "adelaide_hills_sds": "0",
                "brisbane": "0",
                "brisbane_pob": "0",
                "brisbane_sds": "0",
                "sydney": "5.12",
                "sydney_pob": "0",
                "sydney_fringe": "0",
                "melbourne": "0",
                "melbourne_pob": "0",
                "greater_melbourne": "0",
                "active": true
            }
        }
    ],
    "total": 3,
    "total_pages": 1,
    "current_page": 1,
    "page_size": 40,
    "code": 1,
    "message": "ok"
}
```

**Error Response (500):**
```json
{
    "code": 0,
    "data": [],
    "message": "no data"
}
```

### Get Zone Mapping (Legacy)

> ⚠️ **Deprecated**: Will be deprecated after 30th Sep 2025. Use [V2 Get Zone Mapping](#v2-get-zone-mapping-new) instead.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/get_zone_mapping
```

**Success Response (200):**
```json
{
    "result": [
        {
            "postcode": "2823",
            "zone": "NSW_R"
        },
        {
            "postcode": "6302",
            "zone": "WA_R"
        }
    ],
    "total": 2,
    "total_pages": 1,
    "current_page": 1,
    "page_size": 40,
    "code": 1,
    "message": "ok"
}
```

### Get Zone Rates (Legacy)

> ⚠️ **Deprecated**: Will be deprecated after 30th Sep 2025. Use [V2 Get Zone Rates](#v2-get-zone-rates-new) instead.

**Endpoint:**
```
POST https://api.dropshipzone.com.au/get_zone_rates
```

**Success Response (200):**
```json
{
    "result": [
        {
            "sku": "AES-KB22K",
            "act_r": "8",
            "nsw_m": "8",
            "nsw_r": "10",
            "nt_m": "24",
            "nt_r": "31",
            "qld_m": "11",
            "qld_r": "16",
            "remote": "26",
            "sa_m": "8",
            "sa_r": "16",
            "tas_m": "8",
            "tas_r": "11",
            "vic_m": "4",
            "vic_r": "8",
            "wa_m": "17",
            "wa_r": "28"
        }
    ],
    "total": 1,
    "total_pages": 1,
    "current_page": 1,
    "page_size": 40,
    "code": 1,
    "message": "ok"
}
```

---

## Help & FAQ

### How can I get information of all products (e.g., stock quantity)?

1. Call the API method "Get Products" without passing the keywords parameter:
   ```
   GET https://api.dropshipzone.com.au/v2/products?page_no=1&limit=160
   ```

2. The response contains 160 products' information, including `stock_qty`, etc.

3. Continue to pass `page_no` incrementally until all products' information is retrieved.

---

## Important API Response Fields Reference

### Product Fields

| Field | Type | Description |
|-------|------|-------------|
| sku | String | Product SKU identifier |
| title | String | Product title/name |
| desc | String | Product description |
| price | Number | Product cost price |
| stock_qty | Number | Current stock quantity |
| status | String | Stock status ("In Stock" / "Out Of Stock") |
| gallery | Array | Array of product image URLs |
| Category | String | Full category path (e.g., "Appliances > Air Conditioners") |
| weight | Number | Product weight |
| length | Number | Product length |
| width | Number | Product width |
| height | Number | Product height |
| RrpPrice | Number | Recommended Retail Price |
| brand | String | Product brand |
| eancode | String | EAN/Barcode |
| freeshipping | String | "0" or "1" indicating free shipping |

---

## Support

For API support, contact:
- **Email**: support@dropshipzone.com.au
- **Website**: [https://dropshipzone.com.au](https://dropshipzone.com.au)
