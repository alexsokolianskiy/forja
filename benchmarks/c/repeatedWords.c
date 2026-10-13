#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    size_t start;
    size_t len;
    uint64_t hash;
    uint64_t count; /* 0 marks an empty slot */
} slot;

static int is_word_byte(unsigned char c)
{
    return (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9');
}

/* Doubles the table. Words are already distinct, so re-inserting needs no comparison. */
static slot *grow(slot *table, size_t *capacity)
{
    size_t bigger = *capacity * 2;
    slot *next = calloc(bigger, sizeof(slot));
    if (next == NULL) {
        return NULL;
    }
    for (size_t i = 0; i < *capacity; i++) {
        if (table[i].count == 0) {
            continue;
        }
        size_t at = (size_t) table[i].hash & (bigger - 1);
        while (next[at].count != 0) {
            at = (at + 1) & (bigger - 1);
        }
        next[at] = table[i];
    }
    free(table);
    *capacity = bigger;
    return next;
}

int64_t {{symbol}}(const char *text, size_t text_len, int32_t *forja_err)
{
    if (text_len == 0) {
        return 0;
    }

    size_t capacity = 1024;
    size_t used = 0;
    unsigned char *lower = malloc(text_len);
    slot *table = calloc(capacity, sizeof(slot));
    if (lower == NULL || table == NULL) {
        free(lower);
        free(table);
        *forja_err = 1;
        return 0;
    }
    for (size_t i = 0; i < text_len; i++) {
        unsigned char c = (unsigned char) text[i];
        lower[i] = (c >= 'A' && c <= 'Z') ? (unsigned char) (c + 32) : c;
    }

    int64_t repeated = 0;
    size_t i = 0;
    while (i < text_len) {
        if (!is_word_byte(lower[i])) {
            i++;
            continue;
        }
        size_t start = i;
        uint64_t hash = 1469598103934665603ULL; /* FNV-1a */
        while (i < text_len && is_word_byte(lower[i])) {
            hash = (hash ^ lower[i]) * 1099511628211ULL;
            i++;
        }
        size_t len = i - start;

        size_t at = (size_t) hash & (capacity - 1);
        for (;;) {
            slot *s = &table[at];
            if (s->count == 0) {
                s->start = start;
                s->len = len;
                s->hash = hash;
                s->count = 1;
                used++;
                break;
            }
            if (s->hash == hash && s->len == len && memcmp(lower + s->start, lower + start, len) == 0) {
                if (++s->count == 2) {
                    repeated++;
                }
                break;
            }
            at = (at + 1) & (capacity - 1);
        }

        if (used * 2 > capacity) {
            table = grow(table, &capacity);
            if (table == NULL) {
                free(lower);
                *forja_err = 1;
                return 0;
            }
        }
    }

    free(lower);
    free(table);
    return repeated;
}
