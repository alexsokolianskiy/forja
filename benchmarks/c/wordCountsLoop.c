#include "forja_rt.h"

static fj_val {{symbol}}_impl(fj_ctx *ctx, const char *text, size_t text_len)
{
    fj_arr *counts = fj_arr_new(ctx);
    if (text_len == 0) {
        return fj_array(counts);
    }

    /* strtolower() once, then every word is a slice of this buffer: no per-word allocation. */
    char *lower = fj_alloc(ctx, text_len);
    for (size_t i = 0; i < text_len; i++) {
        unsigned char c = (unsigned char) text[i];
        lower[i] = (c >= 'A' && c <= 'Z') ? (char) (c + 32) : (char) c;
    }

    size_t i = 0;
    while (i < text_len) {
        unsigned char c = (unsigned char) lower[i];
        if (!((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9'))) {
            i++;
            continue;
        }
        size_t start = i;
        while (i < text_len) {
            c = (unsigned char) lower[i];
            if (!((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9'))) {
                break;
            }
            i++;
        }
        fj_str word = { lower + start, i - start };
        fj_val *count = fj_get_s(counts, word);
        if (count == NULL) {
            fj_set_s(ctx, counts, word, fj_int(1));
        } else {
            count->u.i++;
        }
    }
    return fj_array(counts);
}
