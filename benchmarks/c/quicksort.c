#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

int64_t *{{symbol}}(const int64_t *a, size_t a_len, int64_t *accel_len, int32_t *accel_err)
{
    *accel_len = 0;
    if (a_len == 0) {
        return NULL;
    }
    int64_t *out = malloc(a_len * sizeof(int64_t));
    /* Each split pushes two ranges and pops one, so 2 * a_len + 2 slots always suffice. */
    int64_t *stack = malloc((2 * a_len + 2) * sizeof(int64_t));
    if (out == NULL || stack == NULL) {
        free(out);
        free(stack);
        *accel_err = 1;
        return NULL;
    }
    memcpy(out, a, a_len * sizeof(int64_t));

    size_t top = 0;
    stack[top++] = 0;
    stack[top++] = (int64_t) a_len - 1;
    while (top > 0) {
        int64_t hi = stack[--top];
        int64_t lo = stack[--top];
        if (lo >= hi) {
            continue;
        }
        int64_t pivot = out[(lo + hi) / 2];
        int64_t i = lo - 1, j = hi + 1;
        for (;;) {
            do { i++; } while (out[i] < pivot);
            do { j--; } while (out[j] > pivot);
            if (i >= j) {
                break;
            }
            int64_t t = out[i];
            out[i] = out[j];
            out[j] = t;
        }
        stack[top++] = lo;
        stack[top++] = j;
        stack[top++] = j + 1;
        stack[top++] = hi;
    }

    free(stack);
    *accel_len = (int64_t) a_len;
    return out;
}
