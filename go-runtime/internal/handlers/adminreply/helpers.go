package adminreply

import "strconv"

func parseInt(s string) int64 {
	n, _ := strconv.ParseInt(s, 10, 64)
	return n
}

func formatID(id int64) string {
	return strconv.FormatInt(id, 10)
}
