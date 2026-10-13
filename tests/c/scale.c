#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>

int64_t *{{symbol}}(const int64_t *xs, size_t xs_len, int64_t k, int64_t *forja_len, int32_t *forja_err)
{
    *forja_len = 0;
    if (xs_len == 0) {
        return NULL;
    }
    int64_t *out = malloc(xs_len * sizeof(int64_t));
    if (out == NULL) {
        *forja_err = 1;
        return NULL;
    }
    for (size_t i = 0; i < xs_len; i++) {
        if (__builtin_mul_overflow(xs[i], k, &out[i])) {
            free(out);
            *forja_err = 1;
            return NULL;
        }
    }
    *forja_len = (int64_t) xs_len;
    return out;
}
