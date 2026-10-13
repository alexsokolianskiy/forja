#include <stdint.h>

int64_t {{symbol}}(int64_t n, int32_t *forja_err)
{
    int64_t out;
    if (__builtin_mul_overflow(n, 3, &out)) {
        *forja_err = 1;
        return 0;
    }
    return out;
}
