#include <stdint.h>
#include <stddef.h>

int64_t {{symbol}}(const int64_t *xs, size_t xs_len, int32_t *accel_err)
{
    int64_t sum = 0;
    for (size_t i = 0; i < xs_len; i++) {
        if (__builtin_add_overflow(sum, xs[i], &sum)) {
            *accel_err = 1;
            return 0;
        }
    }
    return sum;
}
