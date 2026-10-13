/*
 * Forja runtime: PHP values in C.
 *
 * Included by generated code whose PHP method takes or returns values that
 * have no fixed C layout (associative or nested arrays, null, unions).
 * Everything is allocated from a per-call arena that the generated wrapper
 * releases, so translated code never frees anything, and fj_fail() can
 * abandon the call from any depth without leaking.
 */
#ifndef FORJA_RT_H
#define FORJA_RT_H

#include <locale.h>
#include <math.h>
#include <setjmp.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifndef FJ_PHP_VERSION_ID
#define FJ_PHP_VERSION_ID 80300
#endif
#ifndef FJ_MEMORY_LIMIT
#define FJ_MEMORY_LIMIT ((size_t) 1 << 30)
#endif
#define FJ_MAX_DEPTH 128

#define FJ_API static __attribute__((unused))

/* API-BEGIN */
typedef struct fj_ctx fj_ctx;   /* the current call; pass it to everything that allocates */
typedef struct fj_arr fj_arr;   /* a PHP array: ordered map with int and string keys */

typedef struct { const char *p; size_t len; } fj_str;   /* bytes, not NUL-terminated, never modified */

typedef enum { FJ_NULL, FJ_BOOL, FJ_INT, FJ_FLOAT, FJ_STR, FJ_ARR } fj_type;

typedef struct {
    fj_type type;
    union { int64_t i; double d; fj_str s; fj_arr *a; } u;   /* FJ_BOOL uses u.i (0 or 1) */
} fj_val;

/* Abandon the call: the PHP method runs instead. Never returns. Use it for every case you do not
 * translate exactly: a value of an unexpected type, an operation PHP would warn or throw on,
 * int overflow, anything whose PHP conversion rules you are not sure of. */
FJ_API void fj_fail(fj_ctx *ctx);

/* Arena memory, zero-filled, released automatically after the call. Fails the call when exhausted. */
FJ_API void *fj_alloc(fj_ctx *ctx, size_t size);

/* Constructing values. */
FJ_API fj_val fj_null(void);
FJ_API fj_val fj_bool(int b);
FJ_API fj_val fj_int(int64_t i);
FJ_API fj_val fj_float(double d);
FJ_API fj_val fj_string(fj_str s);          /* does not copy: s must stay valid for the whole call */
FJ_API fj_val fj_array(fj_arr *a);
#define FJ_LIT(literal) ((fj_str){ (literal), sizeof(literal) - 1 })

/* Strings. Input strings and everything from the arena stay valid for the whole call. */
FJ_API fj_str fj_str_copy(fj_ctx *ctx, const char *p, size_t len);
FJ_API fj_str fj_str_concat(fj_ctx *ctx, fj_str a, fj_str b);
FJ_API int fj_str_eq(fj_str a, fj_str b);
FJ_API fj_str fj_int_to_str(fj_ctx *ctx, int64_t i);          /* PHP (string) $int */

/* Growing byte buffer for building strings piece by piece. Zero-initialise it: fj_buf b = {0}; */
typedef struct { char *p; size_t len, cap; } fj_buf;
FJ_API void fj_buf_add(fj_ctx *ctx, fj_buf *b, const char *p, size_t len);
FJ_API void fj_buf_addc(fj_ctx *ctx, fj_buf *b, char c);
FJ_API fj_str fj_buf_view(const fj_buf *b);   /* valid only until the buffer is changed again */
FJ_API fj_str fj_buf_take(fj_buf *b);         /* permanent string; the buffer becomes empty */

/* Arrays follow PHP: insertion order is kept; overwriting keeps the position; a string key that is a
 * canonical decimal integer ("12", "-3", not "012" or "1.0") is the int key; fj_push uses the largest
 * int key so far plus one. Arrays are referred to by pointer, so an assignment that PHP treats as a
 * copy ($b = $a; then one of them is modified) needs fj_arr_clone. Arrays you receive as arguments
 * are private to this call and may be modified in place. */
FJ_API fj_arr *fj_arr_new(fj_ctx *ctx);
FJ_API size_t fj_count(const fj_arr *a);
FJ_API fj_val *fj_get_i(fj_arr *a, int64_t key);            /* NULL when the key is absent */
FJ_API fj_val *fj_get_s(fj_arr *a, fj_str key);             /* NULL when the key is absent */
FJ_API void fj_set_i(fj_ctx *ctx, fj_arr *a, int64_t key, fj_val value);
FJ_API void fj_set_s(fj_ctx *ctx, fj_arr *a, fj_str key, fj_val value);   /* copies a new key's bytes */
FJ_API void fj_push(fj_ctx *ctx, fj_arr *a, fj_val value);  /* $a[] = value */
FJ_API void fj_unset_i(fj_ctx *ctx, fj_arr *a, int64_t key);
FJ_API void fj_unset_s(fj_ctx *ctx, fj_arr *a, fj_str key);
FJ_API fj_arr *fj_arr_clone(fj_ctx *ctx, const fj_arr *a);  /* deep copy */

/* foreach ($a as $key => $value):
 *     size_t it = 0; fj_val key; fj_val *value;
 *     while (fj_next(a, &it, &key, &value)) { ... }
 * key is FJ_INT or FJ_STR; *value may be assigned to. Do not add or remove elements of the array
 * being iterated; PHP iterates a snapshot, so clone first if the loop body changes the array. */
FJ_API int fj_next(const fj_arr *a, size_t *it, fj_val *key, fj_val **value);
/* API-END */

/* ------------------------------------------------------------------ arena */

typedef struct fj_chunk {
    struct fj_chunk *next;
    size_t used, cap;
} fj_chunk;

struct fj_ctx {
    fj_chunk *chunks;
    size_t total;
    jmp_buf escape;
};

FJ_API fj_ctx *fj_ctx_new(void)
{
    return calloc(1, sizeof(fj_ctx));
}

FJ_API void fj_ctx_free(fj_ctx *ctx)
{
    fj_chunk *c = ctx->chunks;
    while (c != NULL) {
        fj_chunk *next = c->next;
        free(c);
        c = next;
    }
    free(ctx);
}

FJ_API void fj_fail(fj_ctx *ctx)
{
    longjmp(ctx->escape, 1);
}

FJ_API void *fj_alloc(fj_ctx *ctx, size_t size)
{
    if (size > FJ_MEMORY_LIMIT) {
        fj_fail(ctx);
    }
    size = (size + 15) & ~(size_t) 15;
    fj_chunk *c = ctx->chunks;
    if (c == NULL || c->cap - c->used < size) {
        size_t cap = size > 65536 ? size : 65536;
        if (ctx->total + cap > FJ_MEMORY_LIMIT) {
            fj_fail(ctx);
        }
        c = malloc(sizeof(fj_chunk) + cap);
        if (c == NULL) {
            fj_fail(ctx);
        }
        c->next = ctx->chunks;
        c->used = 0;
        c->cap = cap;
        ctx->chunks = c;
        ctx->total += cap;
    }
    void *p = (char *) (c + 1) + c->used;
    c->used += size;
    memset(p, 0, size);
    return p;
}

/* ----------------------------------------------------------------- values */

FJ_API fj_val fj_null(void) { fj_val v; memset(&v, 0, sizeof v); v.type = FJ_NULL; return v; }
FJ_API fj_val fj_bool(int b) { fj_val v = fj_null(); v.type = FJ_BOOL; v.u.i = b ? 1 : 0; return v; }
FJ_API fj_val fj_int(int64_t i) { fj_val v = fj_null(); v.type = FJ_INT; v.u.i = i; return v; }
FJ_API fj_val fj_float(double d) { fj_val v = fj_null(); v.type = FJ_FLOAT; v.u.d = d; return v; }
FJ_API fj_val fj_string(fj_str s) { fj_val v = fj_null(); v.type = FJ_STR; v.u.s = s; return v; }
FJ_API fj_val fj_array(fj_arr *a) { fj_val v = fj_null(); v.type = FJ_ARR; v.u.a = a; return v; }

/* ---------------------------------------------------------------- strings */

FJ_API fj_str fj_str_copy(fj_ctx *ctx, const char *p, size_t len)
{
    fj_str s = { NULL, len };
    if (len > 0) {
        char *copy = fj_alloc(ctx, len);
        memcpy(copy, p, len);
        s.p = copy;
    }
    return s;
}

FJ_API fj_str fj_str_concat(fj_ctx *ctx, fj_str a, fj_str b)
{
    fj_str s = { NULL, a.len + b.len };
    if (s.len > 0) {
        char *out = fj_alloc(ctx, s.len);
        if (a.len > 0) memcpy(out, a.p, a.len);
        if (b.len > 0) memcpy(out + a.len, b.p, b.len);
        s.p = out;
    }
    return s;
}

FJ_API int fj_str_eq(fj_str a, fj_str b)
{
    return a.len == b.len && (a.len == 0 || memcmp(a.p, b.p, a.len) == 0);
}

FJ_API fj_str fj_int_to_str(fj_ctx *ctx, int64_t i)
{
    char tmp[24];
    int n = snprintf(tmp, sizeof tmp, "%lld", (long long) i);
    return fj_str_copy(ctx, tmp, (size_t) n);
}

FJ_API void fj_buf_add(fj_ctx *ctx, fj_buf *b, const char *p, size_t len)
{
    if (len == 0) {
        return;
    }
    if (b->cap - b->len < len) {
        size_t cap = b->cap < 32 ? 32 : b->cap * 2;
        while (cap - b->len < len) {
            cap *= 2;
        }
        char *bigger = fj_alloc(ctx, cap);
        if (b->len > 0) memcpy(bigger, b->p, b->len);
        b->p = bigger;
        b->cap = cap;
    }
    memcpy(b->p + b->len, p, len);
    b->len += len;
}

FJ_API void fj_buf_addc(fj_ctx *ctx, fj_buf *b, char c)
{
    fj_buf_add(ctx, b, &c, 1);
}

FJ_API fj_str fj_buf_view(const fj_buf *b)
{
    fj_str s = { b->p, b->len };
    return s;
}

FJ_API fj_str fj_buf_take(fj_buf *b)
{
    fj_str s = { b->p, b->len };
    b->p = NULL;
    b->len = 0;
    b->cap = 0;
    return s;
}

/* ----------------------------------------------------------------- arrays */

typedef struct {
    fj_val val;
    fj_str skey;
    int64_t ikey;
    uint64_t hash;
    uint8_t is_str;
    uint8_t deleted;
} fj_ent;

struct fj_arr {
    fj_ctx *ctx;
    fj_ent *ents;       /* in insertion order, including deleted slots */
    size_t n, cap;      /* slots used / allocated */
    size_t live;        /* elements not deleted */
    int64_t next;       /* key fj_push will use */
    uint8_t has_next;   /* 0 until the first int key is seen */
    uint8_t next_full;  /* the largest int key is INT64_MAX: PHP refuses to push */
    uint8_t packed;     /* keys are exactly 0..n-1 in order: slot == key, no index needed */
    uint32_t *index;    /* open addressing: slot + 1, or 0 for empty; built on demand */
    size_t icap;
};

FJ_API fj_arr *fj_arr_new(fj_ctx *ctx)
{
    fj_arr *a = fj_alloc(ctx, sizeof(fj_arr));
    a->ctx = ctx;
    a->packed = 1;
    return a;
}

FJ_API size_t fj_count(const fj_arr *a)
{
    return a->live;
}

FJ_API uint64_t fj__hash_i(int64_t k)
{
    uint64_t x = (uint64_t) k * 0x9E3779B97F4A7C15ULL;
    return x ^ (x >> 32);
}

FJ_API uint64_t fj__hash_s(fj_str s)
{
    uint64_t h = 1469598103934665603ULL;
    for (size_t i = 0; i < s.len; i++) {
        h = (h ^ (unsigned char) s.p[i]) * 1099511628211ULL;
    }
    return h | 1;
}

FJ_API void fj__index_put(fj_arr *a, size_t slot)
{
    size_t at = (size_t) a->ents[slot].hash & (a->icap - 1);
    while (a->index[at] != 0) {
        at = (at + 1) & (a->icap - 1);
    }
    a->index[at] = (uint32_t) (slot + 1);
}

FJ_API void fj__index_build(fj_ctx *ctx, fj_arr *a)
{
    size_t icap = 16;
    while (icap < a->cap * 2 || icap < a->n * 2 + 2) {
        icap *= 2;
    }
    a->index = fj_alloc(ctx, icap * sizeof(uint32_t));
    a->icap = icap;
    for (size_t i = 0; i < a->n; i++) {
        fj__index_put(a, i);
    }
}

/* Slot of a live element, or -1. Small arrays are scanned; larger ones get a hash index on first use. */
FJ_API ptrdiff_t fj__find(fj_arr *a, int is_str, int64_t ikey, fj_str skey, uint64_t hash)
{
    if (a->packed) {
        return (!is_str && ikey >= 0 && (uint64_t) ikey < a->n) ? (ptrdiff_t) ikey : -1;
    }
    if (a->index == NULL) {
        if (a->n > 8) {
            fj__index_build(a->ctx, a);
        } else {
            for (size_t i = 0; i < a->n; i++) {
                fj_ent *e = &a->ents[i];
                if (!e->deleted && e->is_str == is_str && e->hash == hash
                    && (is_str ? fj_str_eq(e->skey, skey) : e->ikey == ikey)) {
                    return (ptrdiff_t) i;
                }
            }
            return -1;
        }
    }
    size_t at = (size_t) hash & (a->icap - 1);
    while (a->index[at] != 0) {
        fj_ent *e = &a->ents[a->index[at] - 1];
        if (!e->deleted && e->is_str == is_str && e->hash == hash
            && (is_str ? fj_str_eq(e->skey, skey) : e->ikey == ikey)) {
            return (ptrdiff_t) (a->index[at] - 1);
        }
        at = (at + 1) & (a->icap - 1);
    }
    return -1;
}

/* Appends without looking for an existing key. */
FJ_API fj_val *fj__append(fj_ctx *ctx, fj_arr *a, int is_str, int64_t ikey, fj_str skey, uint64_t hash, fj_val value)
{
    if (a->n == a->cap) {
        size_t cap = a->cap < 8 ? 8 : a->cap * 2;
        if (cap > UINT32_MAX / 2) {
            fj_fail(ctx);
        }
        fj_ent *bigger = fj_alloc(ctx, cap * sizeof(fj_ent));
        if (a->n > 0) memcpy(bigger, a->ents, a->n * sizeof(fj_ent));
        a->ents = bigger;
        a->cap = cap;
        if (a->index != NULL) {
            fj__index_build(ctx, a);
        }
    }
    if (a->packed && (is_str || ikey != (int64_t) a->n)) {
        a->packed = 0;
    }
    fj_ent *e = &a->ents[a->n];
    e->val = value;
    e->skey = skey;
    e->ikey = ikey;
    e->hash = hash;
    e->is_str = (uint8_t) is_str;
    e->deleted = 0;
    a->n++;
    a->live++;
    if (a->index != NULL) {
        fj__index_put(a, a->n - 1);
    }
    if (!is_str) {
        if (ikey == INT64_MAX) {
            a->next_full = 1;
            a->has_next = 1;
        } else if (!a->has_next) {
            /* Before PHP 8.3 the first push after a negative key used key 0. */
            a->next = (ikey >= 0 || FJ_PHP_VERSION_ID >= 80300) ? ikey + 1 : 0;
            a->has_next = 1;
        } else if (!a->next_full && ikey >= a->next) {
            a->next = ikey + 1;
        }
    }
    return &e->val;
}

/* "12" and "-3" are int keys; "012", "+1", "-0", "1.0", "" and out-of-range numbers are not. */
FJ_API int fj__numeric_key(fj_str s, int64_t *out)
{
    if (s.len == 0 || s.len > 20) {
        return 0;
    }
    size_t i = 0;
    int negative = s.p[0] == '-';
    if (negative) {
        i = 1;
        if (s.len == 1) return 0;
    }
    if (s.p[i] == '0' && (s.len > i + 1 || negative)) {
        return 0;
    }
    uint64_t value = 0;
    for (; i < s.len; i++) {
        unsigned char c = (unsigned char) s.p[i];
        if (c < '0' || c > '9') return 0;
        if (value > (UINT64_MAX - (c - '0')) / 10) return 0;
        value = value * 10 + (c - '0');
    }
    if (negative) {
        if (value > (uint64_t) INT64_MAX + 1) return 0;
        *out = value == (uint64_t) INT64_MAX + 1 ? INT64_MIN : -(int64_t) value;
    } else {
        if (value > (uint64_t) INT64_MAX) return 0;
        *out = (int64_t) value;
    }
    return 1;
}

FJ_API fj_val *fj_get_i(fj_arr *a, int64_t key)
{
    ptrdiff_t slot = fj__find(a, 0, key, (fj_str){ NULL, 0 }, fj__hash_i(key));
    return slot < 0 ? NULL : &a->ents[slot].val;
}

FJ_API fj_val *fj_get_s(fj_arr *a, fj_str key)
{
    int64_t ikey;
    if (fj__numeric_key(key, &ikey)) {
        return fj_get_i(a, ikey);
    }
    ptrdiff_t slot = fj__find(a, 1, 0, key, fj__hash_s(key));
    return slot < 0 ? NULL : &a->ents[slot].val;
}

FJ_API void fj_set_i(fj_ctx *ctx, fj_arr *a, int64_t key, fj_val value)
{
    uint64_t hash = fj__hash_i(key);
    ptrdiff_t slot = fj__find(a, 0, key, (fj_str){ NULL, 0 }, hash);
    if (slot >= 0) {
        a->ents[slot].val = value;
    } else {
        fj__append(ctx, a, 0, key, (fj_str){ NULL, 0 }, hash, value);
    }
}

FJ_API void fj_set_s(fj_ctx *ctx, fj_arr *a, fj_str key, fj_val value)
{
    int64_t ikey;
    if (fj__numeric_key(key, &ikey)) {
        fj_set_i(ctx, a, ikey, value);
        return;
    }
    uint64_t hash = fj__hash_s(key);
    ptrdiff_t slot = fj__find(a, 1, 0, key, hash);
    if (slot >= 0) {
        a->ents[slot].val = value;
    } else {
        fj__append(ctx, a, 1, 0, fj_str_copy(ctx, key.p, key.len), hash, value);
    }
}

FJ_API void fj_push(fj_ctx *ctx, fj_arr *a, fj_val value)
{
    if (a->next_full) {
        fj_fail(ctx); /* PHP: "Cannot add element to the array as the next element is already occupied" */
    }
    int64_t key = a->has_next ? a->next : 0;
    fj__append(ctx, a, 0, key, (fj_str){ NULL, 0 }, fj__hash_i(key), value);
}

FJ_API void fj__unset(fj_ctx *ctx, fj_arr *a, int is_str, int64_t ikey, fj_str skey, uint64_t hash)
{
    ptrdiff_t slot = fj__find(a, is_str, ikey, skey, hash);
    if (slot < 0) {
        return;
    }
    a->packed = 0; /* slot numbers no longer equal keys; lookups scan or use the index from now on */
    a->ents[slot].deleted = 1;
    a->live--;
}

FJ_API void fj_unset_i(fj_ctx *ctx, fj_arr *a, int64_t key)
{
    fj__unset(ctx, a, 0, key, (fj_str){ NULL, 0 }, fj__hash_i(key));
}

FJ_API void fj_unset_s(fj_ctx *ctx, fj_arr *a, fj_str key)
{
    int64_t ikey;
    if (fj__numeric_key(key, &ikey)) {
        fj_unset_i(ctx, a, ikey);
    } else {
        fj__unset(ctx, a, 1, 0, key, fj__hash_s(key));
    }
}

FJ_API int fj_next(const fj_arr *a, size_t *it, fj_val *key, fj_val **value)
{
    while (*it < a->n) {
        fj_ent *e = &a->ents[(*it)++];
        if (e->deleted) {
            continue;
        }
        *key = e->is_str ? fj_string(e->skey) : fj_int(e->ikey);
        *value = &e->val;
        return 1;
    }
    return 0;
}

FJ_API fj_arr *fj__clone(fj_ctx *ctx, const fj_arr *a, int depth)
{
    if (depth > FJ_MAX_DEPTH) {
        fj_fail(ctx);
    }
    fj_arr *copy = fj_arr_new(ctx);
    for (size_t i = 0; i < a->n; i++) {
        const fj_ent *e = &a->ents[i];
        if (e->deleted) {
            continue;
        }
        fj_val v = e->val;
        if (v.type == FJ_ARR) {
            v.u.a = fj__clone(ctx, v.u.a, depth + 1);
        }
        fj__append(ctx, copy, e->is_str, e->ikey, e->skey, e->hash, v);
    }
    /* The push position survives deletions in PHP, so it is copied rather than recomputed. */
    copy->next = a->next;
    copy->has_next = a->has_next;
    copy->next_full = a->next_full;
    return copy;
}

FJ_API fj_arr *fj_arr_clone(fj_ctx *ctx, const fj_arr *a)
{
    return fj__clone(ctx, a, 0);
}

/* ------------------------------------------- transport: PHP serialize format */

typedef struct {
    fj_ctx *ctx;
    const char *p, *end;
} fj__reader;

FJ_API void fj__expect(fj__reader *r, char c)
{
    if (r->p >= r->end || *r->p != c) {
        fj_fail(r->ctx);
    }
    r->p++;
}

FJ_API int64_t fj__read_int(fj__reader *r, char terminator)
{
    int negative = 0;
    if (r->p < r->end && *r->p == '-') {
        negative = 1;
        r->p++;
    }
    if (r->p >= r->end || *r->p < '0' || *r->p > '9') {
        fj_fail(r->ctx);
    }
    uint64_t value = 0;
    while (r->p < r->end && *r->p >= '0' && *r->p <= '9') {
        uint64_t digit = (uint64_t) (*r->p - '0');
        if (value > (UINT64_MAX - digit) / 10) {
            fj_fail(r->ctx);
        }
        value = value * 10 + digit;
        r->p++;
    }
    fj__expect(r, terminator);
    if (negative) {
        if (value > (uint64_t) INT64_MAX + 1) fj_fail(r->ctx);
        return value == (uint64_t) INT64_MAX + 1 ? INT64_MIN : -(int64_t) value;
    }
    if (value > (uint64_t) INT64_MAX) fj_fail(r->ctx);
    return (int64_t) value;
}

FJ_API double fj__read_float(fj__reader *r)
{
    char tmp[64];
    size_t n = 0;
    while (r->p < r->end && *r->p != ';') {
        if (n + 1 >= sizeof tmp) fj_fail(r->ctx);
        tmp[n++] = *r->p++;
    }
    tmp[n] = '\0';
    fj__expect(r, ';');
    if (strcmp(tmp, "INF") == 0) return INFINITY;
    if (strcmp(tmp, "-INF") == 0) return -INFINITY;
    if (strcmp(tmp, "NAN") == 0) return NAN;
    /* strtod honours LC_NUMERIC; PHP always writes a dot. */
    const char *point = localeconv()->decimal_point;
    if (point != NULL && point[0] != '.' && point[0] != '\0') {
        for (size_t i = 0; i < n; i++) {
            if (tmp[i] == '.') tmp[i] = point[0];
        }
    }
    char *stop = NULL;
    double d = strtod(tmp, &stop);
    if (n == 0 || stop != tmp + n) {
        fj_fail(r->ctx);
    }
    return d;
}

FJ_API fj_str fj__read_str(fj__reader *r)
{
    int64_t len = fj__read_int(r, ':');
    fj__expect(r, '"');
    if (len < 0 || (uint64_t) len > (uint64_t) (r->end - r->p)) {
        fj_fail(r->ctx);
    }
    fj_str s = { r->p, (size_t) len };   /* a view into the input: no copy */
    r->p += len;
    fj__expect(r, '"');
    fj__expect(r, ';');
    return s;
}

FJ_API fj_val fj__read(fj__reader *r, int depth)
{
    if (depth > FJ_MAX_DEPTH || r->end - r->p < 2) {
        fj_fail(r->ctx);
    }
    char tag = *r->p++;
    if (tag == 'N') {
        fj__expect(r, ';');
        return fj_null();
    }
    fj__expect(r, ':');
    switch (tag) {
        case 'b': return fj_bool(fj__read_int(r, ';') != 0);
        case 'i': return fj_int(fj__read_int(r, ';'));
        case 'd': return fj_float(fj__read_float(r));
        case 's': return fj_string(fj__read_str(r));
        case 'a': {
            int64_t count = fj__read_int(r, ':');
            fj__expect(r, '{');
            if (count < 0 || count > r->end - r->p) fj_fail(r->ctx);
            fj_arr *a = fj_arr_new(r->ctx);
            for (int64_t i = 0; i < count; i++) {
                if (r->end - r->p < 2) fj_fail(r->ctx);
                char key_tag = *r->p++;
                fj__expect(r, ':');
                if (key_tag == 'i') {
                    int64_t key = fj__read_int(r, ';');
                    fj_val v = fj__read(r, depth + 1);
                    fj__append(r->ctx, a, 0, key, (fj_str){ NULL, 0 }, fj__hash_i(key), v);
                } else if (key_tag == 's') {
                    fj_str key = fj__read_str(r);
                    fj_val v = fj__read(r, depth + 1);
                    fj__append(r->ctx, a, 1, 0, key, fj__hash_s(key), v);
                } else {
                    fj_fail(r->ctx);
                }
            }
            fj__expect(r, '}');
            return fj_array(a);
        }
        default:
            /* Objects, enums and references have no C counterpart: PHP handles the call. */
            fj_fail(r->ctx);
    }
    return fj_null();
}

FJ_API fj_val fj_unserialize(fj_ctx *ctx, const char *p, size_t len)
{
    fj__reader r = { ctx, p, p + len };
    fj_val v = fj__read(&r, 0);
    if (r.p != r.end) {
        fj_fail(ctx);
    }
    return v;
}

FJ_API void fj__write(fj_ctx *ctx, fj_buf *out, fj_val v, int depth)
{
    char tmp[64];
    int n;
    if (depth > FJ_MAX_DEPTH) {
        fj_fail(ctx);
    }
    switch (v.type) {
        case FJ_NULL:
            fj_buf_add(ctx, out, "N;", 2);
            break;
        case FJ_BOOL:
            fj_buf_add(ctx, out, v.u.i ? "b:1;" : "b:0;", 4);
            break;
        case FJ_INT:
            n = snprintf(tmp, sizeof tmp, "i:%lld;", (long long) v.u.i);
            fj_buf_add(ctx, out, tmp, (size_t) n);
            break;
        case FJ_FLOAT:
            if (isnan(v.u.d)) {
                fj_buf_add(ctx, out, "d:NAN;", 6);
            } else if (isinf(v.u.d)) {
                fj_buf_add(ctx, out, v.u.d > 0 ? "d:INF;" : "d:-INF;", v.u.d > 0 ? 6 : 7);
            } else {
                /* 17 significant digits read back as the same double. */
                n = snprintf(tmp, sizeof tmp, "d:%.17g;", v.u.d);
                for (int i = 2; i < n - 1; i++) {
                    char c = tmp[i];
                    if (!((c >= '0' && c <= '9') || c == '-' || c == '+' || c == 'e' || c == 'E' || c == '.')) {
                        tmp[i] = '.'; /* a locale-specific decimal separator */
                    }
                }
                fj_buf_add(ctx, out, tmp, (size_t) n);
            }
            break;
        case FJ_STR:
            n = snprintf(tmp, sizeof tmp, "s:%zu:\"", v.u.s.len);
            fj_buf_add(ctx, out, tmp, (size_t) n);
            fj_buf_add(ctx, out, v.u.s.p, v.u.s.len);
            fj_buf_add(ctx, out, "\";", 2);
            break;
        case FJ_ARR: {
            n = snprintf(tmp, sizeof tmp, "a:%zu:{", v.u.a->live);
            fj_buf_add(ctx, out, tmp, (size_t) n);
            size_t it = 0;
            fj_val key, *value;
            while (fj_next(v.u.a, &it, &key, &value)) {
                fj__write(ctx, out, key, depth + 1);
                fj__write(ctx, out, *value, depth + 1);
            }
            fj_buf_addc(ctx, out, '}');
            break;
        }
        default:
            fj_fail(ctx);
    }
}

/* Serialises into a malloc'd buffer that outlives the arena; the caller releases it with free(). */
FJ_API char *fj_serialize(fj_ctx *ctx, fj_val v, int64_t *len)
{
    fj_buf out = { NULL, 0, 0 };
    fj__write(ctx, &out, v, 0);
    char *result = malloc(out.len);
    if (result == NULL) {
        fj_fail(ctx);
    }
    memcpy(result, out.p, out.len);
    *len = (int64_t) out.len;
    return result;
}

#endif
