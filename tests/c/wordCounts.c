#include "forja_rt.h"

static fj_val {{symbol}}_impl(fj_ctx *ctx, const char *text, size_t text_len)
{
    fj_arr *counts = fj_arr_new(ctx);
    fj_buf word = {0};
    for (size_t i = 0; i <= text_len; i++) {
        unsigned char code = i < text_len ? (unsigned char) text[i] : 0;
        if (code >= 65 && code <= 90) {
            code += 32;
        }
        if ((code >= 97 && code <= 122) || (code >= 48 && code <= 57)) {
            fj_buf_addc(ctx, &word, (char) code);
        } else if (word.len > 0) {
            fj_val *count = fj_get_s(counts, fj_buf_view(&word));
            if (count == NULL) {
                fj_set_s(ctx, counts, fj_buf_view(&word), fj_int(1));
            } else {
                count->u.i++;
            }
            word.len = 0;
        }
    }
    return fj_array(counts);
}
